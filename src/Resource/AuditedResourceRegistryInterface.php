<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resource;

interface AuditedResourceRegistryInterface
{
    /** The audited resource an entity belongs to, null when the entity is not audited. */
    public function match(object $entity): ?AuditedResource;

    public function get(string $name): ?AuditedResource;

    /** @return array<string, AuditedResource> */
    public function all(): array;
}
