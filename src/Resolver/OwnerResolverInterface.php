<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

/**
 * Finds the parent of an audited entity (the product of a variant, the order of a payment...).
 * Implementations are autoconfigured with the "tmonier_audit_log.owner_resolver" tag; the first
 * one supporting the entity wins (tag priority).
 */
interface OwnerResolverInterface
{
    public function supports(object $entity, AuditedResource $resource): bool;

    public function resolve(object $entity, AuditedResource $resource, EntityManagerInterface $entityManager): ?object;
}
