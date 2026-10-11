<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resource;

/** An audited resource type, as configured under "tmonier_sylius_audit_log.resources". */
final class AuditedResource
{
    /**
     * @param class-string $class
     * @param list<string> $ignoredFields
     */
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly ?string $owner = null,
        public readonly ?string $ownerPropertyPath = null,
        public readonly array $ignoredFields = [],
    ) {
    }
}
