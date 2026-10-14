<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resource;

/** An audited resource type, as configured under "tmonier_sylius_audit_log.resources". */
final readonly class AuditedResource
{
    /**
     * @param class-string $class
     * @param list<string> $ignoredFields
     */
    public function __construct(
        public string $name,
        public string $class,
        public ?string $owner = null,
        public ?string $ownerPropertyPath = null,
        public array $ignoredFields = [],
    ) {
    }
}
