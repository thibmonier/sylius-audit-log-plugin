<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Diff;

use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

/**
 * Changes of one audited entity collected during onFlush, written after the flush.
 *
 * Field changes are {"old": v, "new": v}, {"masked": true} or {"added": [refs], "removed": [refs]}
 * where values may still be ObjectReference instances until finalized.
 */
final class PendingEntry
{
    /** @var array<string, array<string, mixed>> */
    private array $changes = [];

    public function __construct(
        public string $action,
        public readonly AuditedResource $resource,
        public readonly ObjectReference $subject,
        public readonly ?AuditedResource $ownerResource = null,
        public readonly ?ObjectReference $owner = null,
    ) {
    }

    public function setFieldChange(string $field, bool|float|int|string|ObjectReference|null $old, bool|float|int|string|ObjectReference|null $new): void
    {
        $this->changes[$field] = ['old' => $old, 'new' => $new];
    }

    public function setMaskedField(string $field): void
    {
        $this->changes[$field] = ['masked' => true];
    }

    /**
     * @param list<ObjectReference> $added
     * @param list<ObjectReference> $removed
     */
    public function addCollectionChange(string $field, array $added, array $removed): void
    {
        /** @var array{added?: list<ObjectReference>, removed?: list<ObjectReference>} $current */
        $current = $this->changes[$field] ?? [];
        $added = [...($current['added'] ?? []), ...$added];
        $removed = [...($current['removed'] ?? []), ...$removed];

        // A collection replaced by another one shows up both as deleted and updated.
        $kept = array_filter($added, static function (ObjectReference $reference) use ($removed): bool {
            foreach ($removed as $other) {
                if ($reference->isSameAs($other)) {
                    return false;
                }
            }

            return true;
        });
        $dropped = array_filter($removed, static function (ObjectReference $reference) use ($added): bool {
            foreach ($added as $other) {
                if ($reference->isSameAs($other)) {
                    return false;
                }
            }

            return true;
        });

        if ([] === $kept && [] === $dropped) {
            unset($this->changes[$field]);

            return;
        }

        $this->changes[$field] = ['added' => array_values($kept), 'removed' => array_values($dropped)];
    }

    public function hasChanges(): bool
    {
        return [] !== $this->changes;
    }

    public function isWorthRecording(): bool
    {
        return AuditLogEntryInterface::ACTION_UPDATE !== $this->action || $this->hasChanges();
    }

    /** @return array<string, array<string, mixed>> */
    public function getRawChanges(): array
    {
        return $this->changes;
    }

    /**
     * @param \Closure(ObjectReference): array{id: ?string, label: ?string} $finalizeReference
     *
     * @return array<string, array<string, mixed>>
     */
    public function finalizeChanges(\Closure $finalizeReference): array
    {
        $finalize = static function (mixed $value) use ($finalizeReference): mixed {
            if ($value instanceof ObjectReference) {
                return $finalizeReference($value);
            }
            if (is_array($value)) {
                return array_map(static fn (mixed $item): mixed => $item instanceof ObjectReference ? $finalizeReference($item) : $item, $value);
            }

            return $value;
        };

        $changes = [];
        foreach ($this->changes as $field => $change) {
            $changes[$field] = array_map($finalize, $change);
        }

        return $changes;
    }
}
