<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Recorder;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Sylius\Resource\Model\TranslatableInterface;
use Sylius\Resource\Model\TranslationInterface;
use Tmonier\SyliusAuditLogPlugin\Diff\FieldPolicy;
use Tmonier\SyliusAuditLogPlugin\Diff\ObjectReference;
use Tmonier\SyliusAuditLogPlugin\Diff\PendingEntry;
use Tmonier\SyliusAuditLogPlugin\Diff\ValueNormalizer;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Resolver\CompositeOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\LabelResolverInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistryInterface;

/**
 * Reads the unit of work during onFlush and builds one pending entry per audited entity.
 * Translation changes are folded into their translatable ("name (fr_FR)"), collection changes
 * into the entity owning the collection.
 */
final class ChangeCollector
{
    public function __construct(
        private readonly AuditedResourceRegistryInterface $registry,
        private readonly CompositeOwnerResolver $ownerResolver,
        private readonly LabelResolverInterface $labelResolver,
        private readonly ValueNormalizer $normalizer,
        private readonly FieldPolicy $fieldPolicy,
    ) {
    }

    /** @return list<PendingEntry> */
    public function collect(EntityManagerInterface $entityManager): array
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        /** @var array<int, PendingEntry> $entries */
        $entries = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->collectEntity($entityManager, $entity, AuditLogEntryInterface::ACTION_CREATE, $entries);
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->collectEntity($entityManager, $entity, AuditLogEntryInterface::ACTION_UPDATE, $entries);
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->collectEntity($entityManager, $entity, AuditLogEntryInterface::ACTION_DELETE, $entries);
        }
        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $this->collectCollection($entityManager, $collection, true, $entries);
        }
        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $this->collectCollection($entityManager, $collection, false, $entries);
        }

        return array_values(array_filter($entries, static fn (PendingEntry $entry): bool => $entry->isWorthRecording()));
    }

    /** Builds a reference with the id and label known at this point (completed after the flush). */
    public function reference(EntityManagerInterface $entityManager, object $entity): ObjectReference
    {
        return new ObjectReference($entity, $this->idOf($entityManager, $entity), $this->labelResolver->resolve($entity));
    }

    public function idOf(EntityManagerInterface $entityManager, object $entity): ?string
    {
        try {
            $identifier = $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);
        } catch (\Throwable) {
            return null;
        }

        $parts = array_filter($identifier, static fn (mixed $value): bool => null !== $value);
        if ([] === $parts) {
            return null;
        }

        return implode('-', array_map(static fn (mixed $value): string => is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value), $parts));
    }

    /** @param array<int, PendingEntry> $entries */
    private function collectEntity(EntityManagerInterface $entityManager, object $entity, string $action, array &$entries): void
    {
        if ($entity instanceof TranslationInterface) {
            $this->collectTranslation($entityManager, $entity, $action, $entries);

            return;
        }

        $resource = $this->registry->match($entity);
        if (null === $resource) {
            return;
        }

        $entry = $this->entryFor($entityManager, $entity, $resource, $entries);
        $entry->action = $action;

        foreach ($this->fieldChanges($entityManager, $entity, $action) as $field => [$old, $new]) {
            $this->recordField($entityManager, $entry, $resource, $field, $field, $old, $new);
        }
    }

    /** @param array<int, PendingEntry> $entries */
    private function collectTranslation(EntityManagerInterface $entityManager, TranslationInterface $translation, string $action, array &$entries): void
    {
        try {
            $translatable = $translation->getTranslatable();
        } catch (\Throwable) {
            return;
        }

        $resource = $this->registry->match($translatable);
        if (null === $resource) {
            return;
        }

        $entry = $this->entryFor($entityManager, $translatable, $resource, $entries);
        $metadata = $entityManager->getClassMetadata($translation::class);
        $locale = $translation->getLocale() ?? '?';

        foreach ($this->fieldChanges($entityManager, $translation, $action) as $field => [$old, $new]) {
            if ('locale' === $field || $metadata->hasAssociation($field) && $this->isTranslatableAssociation($old, $new)) {
                continue;
            }

            $this->recordField($entityManager, $entry, $resource, $field, sprintf('%s (%s)', $field, $locale), $old, $new);
        }
    }

    /**
     * @param PersistentCollection<array-key, object> $collection
     * @param array<int, PendingEntry> $entries
     */
    private function collectCollection(EntityManagerInterface $entityManager, PersistentCollection $collection, bool $deleted, array &$entries): void
    {
        $owner = $collection->getOwner();
        if (null === $owner) {
            return;
        }

        $resource = $this->registry->match($owner);
        if (null === $resource || $entityManager->getUnitOfWork()->isScheduledForDelete($owner)) {
            return;
        }

        $field = self::fieldName($collection->getMapping());
        if (null === $field || $this->fieldPolicy->isIgnored($field, $resource)) {
            return;
        }

        $entry = $this->entryFor($entityManager, $owner, $resource, $entries);
        if ($this->fieldPolicy->isMasked($field)) {
            $entry->setMaskedField($field);

            return;
        }

        $toReferences = fn (array $objects): array => array_values(array_map(
            fn (object $object): ObjectReference => $this->reference($entityManager, $object),
            array_filter($objects, 'is_object'),
        ));

        if ($deleted) {
            $entry->addCollectionChange($field, [], $toReferences($collection->getSnapshot()));

            return;
        }

        $entry->addCollectionChange($field, $toReferences($collection->getInsertDiff()), $toReferences($collection->getDeleteDiff()));
    }

    /** @param array<int, PendingEntry> $entries */
    private function entryFor(EntityManagerInterface $entityManager, object $entity, AuditedResource $resource, array &$entries): PendingEntry
    {
        $key = spl_object_id($entity);
        if (isset($entries[$key])) {
            return $entries[$key];
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $action = match (true) {
            $unitOfWork->isScheduledForInsert($entity) => AuditLogEntryInterface::ACTION_CREATE,
            $unitOfWork->isScheduledForDelete($entity) => AuditLogEntryInterface::ACTION_DELETE,
            default => AuditLogEntryInterface::ACTION_UPDATE,
        };

        $root = $this->ownerResolver->resolveRoot($entity, $resource, $entityManager);

        return $entries[$key] = new PendingEntry(
            $action,
            $resource,
            $this->reference($entityManager, $entity),
            $root[0] ?? null,
            null !== $root ? $this->reference($entityManager, $root[1]) : null,
        );
    }

    /** @return iterable<string, array{mixed, mixed}> */
    private function fieldChanges(EntityManagerInterface $entityManager, object $entity, string $action): iterable
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata($entity::class);
        $identifiers = $metadata->getIdentifierFieldNames();

        if (AuditLogEntryInterface::ACTION_DELETE === $action) {
            $changes = [];
            foreach ($unitOfWork->getOriginalEntityData($entity) as $field => $value) {
                $changes[$field] = [$value, null];
            }
        } else {
            $changes = $unitOfWork->getEntityChangeSet($entity);
        }

        foreach ($changes as $field => $change) {
            $field = (string) $field;
            [$old, $new] = [$change[0] ?? null, $change[1] ?? null];
            if (in_array($field, $identifiers, true) || $old instanceof Collection || $new instanceof Collection) {
                continue;
            }
            if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
                continue;
            }

            yield $field => [$old, $new];
        }
    }

    private function recordField(EntityManagerInterface $entityManager, PendingEntry $entry, AuditedResource $resource, string $field, string $key, mixed $old, mixed $new): void
    {
        if ($this->fieldPolicy->isIgnored($field, $resource)) {
            return;
        }

        if ($this->fieldPolicy->isMasked($field)) {
            if ($old !== $new) {
                $entry->setMaskedField($key);
            }

            return;
        }

        $referenceFactory = fn (object $object): ?ObjectReference => $this->isEntity($entityManager, $object)
            ? $this->reference($entityManager, $object)
            : null;

        $old = $this->normalizer->normalize($old, $referenceFactory);
        $new = $this->normalizer->normalize($new, $referenceFactory);

        if ($old === $new || ($old instanceof ObjectReference && $old->isSameAs($new)) || self::sameNumber($old, $new)) {
            return;
        }

        $entry->setFieldChange($key, $old, $new);
    }

    /** @param mixed $mapping array with Doctrine ORM 2, object with ORM 3 */
    private static function fieldName(mixed $mapping): ?string
    {
        if ($mapping instanceof AssociationMapping) {
            return $mapping->fieldName;
        }

        return is_array($mapping) && is_string($mapping['fieldName'] ?? null) ? $mapping['fieldName'] : null;
    }

    /** Decimal columns are hydrated as strings ("0.20000") while the application sets floats. */
    private static function sameNumber(mixed $old, mixed $new): bool
    {
        return is_numeric($old) && is_numeric($new) && (float) $old === (float) $new;
    }

    private function isEntity(EntityManagerInterface $entityManager, object $object): bool
    {
        try {
            $entityManager->getClassMetadata($object::class);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function isTranslatableAssociation(mixed $old, mixed $new): bool
    {
        return $old instanceof TranslatableInterface || $new instanceof TranslatableInterface || (null === $old && null === $new);
    }
}
