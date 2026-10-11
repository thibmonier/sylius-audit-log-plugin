<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Diff;

/**
 * A reference to an entity captured during onFlush. Inserted entities get their id only once
 * the flush is done, and deleted ones lose it: the id and label are captured when known and
 * completed after the flush.
 */
final class ObjectReference
{
    public function __construct(
        public readonly object $object,
        public ?string $id = null,
        public ?string $label = null,
    ) {
    }

    public function isSameAs(mixed $other): bool
    {
        return $other instanceof self && $other->object === $this->object;
    }
}
