<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

/** Reads the "owner_property_path" configured for the resource. */
final readonly class PropertyPathOwnerResolver implements OwnerResolverInterface
{
    public function __construct(private PropertyAccessorInterface $propertyAccessor)
    {
    }

    public function supports(object $entity, AuditedResource $resource): bool
    {
        return null !== $resource->ownerPropertyPath;
    }

    public function resolve(object $entity, AuditedResource $resource, EntityManagerInterface $entityManager): ?object
    {
        if (null === $resource->ownerPropertyPath) {
            return null;
        }

        try {
            $owner = $this->propertyAccessor->getValue($entity, $resource->ownerPropertyPath);
        } catch (\Throwable) {
            return null;
        }

        return is_object($owner) ? $owner : null;
    }
}
