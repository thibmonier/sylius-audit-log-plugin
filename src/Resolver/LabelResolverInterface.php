<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

/**
 * Gives the human label stored with an entry ("Knitted wool hat", "#000042"...). Implementations
 * are autoconfigured with the "tmonier_audit_log.label_resolver" tag; the first one supporting
 * the object wins (tag priority).
 */
interface LabelResolverInterface
{
    public function supports(object $object): bool;

    public function resolve(object $object): ?string;
}
