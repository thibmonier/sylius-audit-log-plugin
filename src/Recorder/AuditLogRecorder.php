<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusAuditLogPlugin\Context\AuditContextProviderInterface;
use Tmonier\SyliusAuditLogPlugin\Context\RecordingPolicy;
use Tmonier\SyliusAuditLogPlugin\Diff\ObjectReference;
use Tmonier\SyliusAuditLogPlugin\Diff\PendingEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Event\AuditLogEntryEvent;
use Tmonier\SyliusAuditLogPlugin\Resolver\LabelResolverInterface;

/**
 * Doctrine listener: changes are read in onFlush (old values are still known) and written in
 * postFlush (inserted entities have their ids). Each flush pushes its batch on a stack popped by
 * its postFlush, so that a flush nested in another postFlush listener keeps its own changes and
 * a failed flush (no postFlush) never leaks into the next one.
 *
 * Recording errors are logged and swallowed: the audit log never breaks the audited change.
 */
final class AuditLogRecorder implements ResetInterface
{
    /** @var array<int, list<list<PendingEntry>>> pending batches by entity manager */
    private array $stacks = [];

    /** @param FactoryInterface<AuditLogEntryInterface> $entryFactory */
    public function __construct(
        private readonly ChangeCollector $collector,
        private readonly AuditLogWriter $writer,
        private readonly AuditContextProviderInterface $contextProvider,
        private readonly RecordingPolicy $recordingPolicy,
        private readonly LabelResolverInterface $labelResolver,
        private readonly FactoryInterface $entryFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $batch = [];

        try {
            if ($this->recordingPolicy->shouldRecord()) {
                $batch = $this->collector->collect($entityManager);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('The audit log could not read the changes of a flush.', ['exception' => $exception]);
        }

        $this->stacks[spl_object_id($entityManager)][] = $batch;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $key = spl_object_id($entityManager);
        if ([] === ($this->stacks[$key] ?? [])) {
            return;
        }

        $batch = array_pop($this->stacks[$key]);
        if ([] === $batch) {
            return;
        }

        try {
            $this->writer->write($entityManager, $this->buildEntries($entityManager, $batch));
        } catch (\Throwable $exception) {
            $this->logger->error('The audit log could not write the changes of a flush.', ['exception' => $exception]);
        }
    }

    public function reset(): void
    {
        $this->stacks = [];
    }

    /**
     * @param list<PendingEntry> $batch
     *
     * @return list<AuditLogEntryInterface>
     */
    private function buildEntries(EntityManagerInterface $entityManager, array $batch): array
    {
        $context = $this->contextProvider->getContext();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        $finalize = fn (ObjectReference $reference): array => $this->finalizeReference($entityManager, $reference);

        $entries = [];
        foreach ($batch as $pending) {
            $subject = $finalize($pending->subject);
            $owner = null !== $pending->owner ? $finalize($pending->owner) : null;

            $entry = $this->entryFactory->createNew();
            $entry->setCreatedAt($now);
            $entry->setAction($pending->action);
            $entry->setResourceType($pending->resource->name);
            $entry->setResourceClass($entityManager->getClassMetadata($pending->subject->object::class)->getName());
            $entry->setResourceId($subject['id']);
            $entry->setResourceLabel($subject['label']);
            $entry->setOwnerType($pending->ownerResource->name ?? $pending->resource->name);
            $entry->setOwnerId(null !== $owner ? $owner['id'] : $subject['id']);
            $entry->setChanges($pending->finalizeChanges($finalize));
            $entry->setActorType($context->actorType);
            $entry->setActorId($context->actorId);
            $entry->setActorName(null !== $context->actorName ? mb_substr($context->actorName, 0, 255) : null);
            $entry->setSource($context->source);
            $entry->setIpAddress($context->ipAddress);
            $entry->setRequestId($context->requestId);
            $entry->setRoute($context->route);

            $event = new AuditLogEntryEvent($entry, $pending->subject->object);
            $this->eventDispatcher->dispatch($event, AuditLogEntryEvent::PRE_WRITE);
            if (!$event->isSkipped()) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array{id: ?string, label: ?string} */
    private function finalizeReference(EntityManagerInterface $entityManager, ObjectReference $reference): array
    {
        $reference->id ??= $this->collector->idOf($entityManager, $reference->object);
        $reference->label ??= $this->labelResolver->resolve($reference->object);

        if (null === $reference->label) {
            $class = $reference->object::class;
            $short = false === ($position = strrpos($class, '\\')) ? $class : substr($class, $position + 1);
            $reference->label = null !== $reference->id ? sprintf('%s #%s', $short, $reference->id) : $short;
        }

        return ['id' => $reference->id, 'label' => $reference->label];
    }
}
