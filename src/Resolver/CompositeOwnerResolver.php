<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistryInterface;

/**
 * Climbs the owner chain up to the aggregate root (channel pricing -> variant -> product), so
 * that the history card of a product lists the changes of its variants and prices.
 */
final class CompositeOwnerResolver
{
    private const MAX_DEPTH = 5;

    /** @param iterable<OwnerResolverInterface> $resolvers */
    public function __construct(
        private readonly iterable $resolvers,
        private readonly AuditedResourceRegistryInterface $registry,
    ) {
    }

    /** @return array{AuditedResource, object}|null the root and its resource, null when the entity is its own root */
    public function resolveRoot(object $entity, AuditedResource $resource, EntityManagerInterface $entityManager): ?array
    {
        $root = null;
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $owner = $this->resolveOwner($entity, $resource, $entityManager);
            $ownerResource = null !== $owner ? $this->registry->match($owner) : null;
            if (null === $owner || null === $ownerResource || $owner === $entity) {
                break;
            }

            $root = [$ownerResource, $owner];
            $entity = $owner;
            $resource = $ownerResource;
        }

        return $root;
    }

    private function resolveOwner(object $entity, AuditedResource $resource, EntityManagerInterface $entityManager): ?object
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($entity, $resource)) {
                $owner = $resolver->resolve($entity, $resource, $entityManager);
                if (null !== $owner) {
                    return $owner;
                }
            }
        }

        return null;
    }
}
