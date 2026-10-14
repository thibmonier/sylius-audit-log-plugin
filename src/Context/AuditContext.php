<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Context;

/** Who made the changes of a flush, from where. */
final readonly class AuditContext
{
    public function __construct(
        public string $actorType,
        public ?int $actorId,
        public ?string $actorName,
        public string $source,
        public ?string $ipAddress,
        public string $requestId,
        public ?string $route,
    ) {
    }
}
