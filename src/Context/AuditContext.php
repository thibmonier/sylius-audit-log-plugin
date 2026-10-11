<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Context;

/** Who made the changes of a flush, from where. */
final class AuditContext
{
    public function __construct(
        public readonly string $actorType,
        public readonly ?int $actorId,
        public readonly ?string $actorName,
        public readonly string $source,
        public readonly ?string $ipAddress,
        public readonly string $requestId,
        public readonly ?string $route,
    ) {
    }
}
