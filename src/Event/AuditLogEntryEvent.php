<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/**
 * Dispatched before an entry is written: listeners can enrich it (custom entry class, extra
 * fields), rewrite its changes or skip it.
 */
final class AuditLogEntryEvent extends Event
{
    public const PRE_WRITE = 'tmonier_audit_log.entry.pre_write';

    private bool $skipped = false;

    public function __construct(
        private readonly AuditLogEntryInterface $entry,
        private readonly object $subject,
    ) {
    }

    public function getEntry(): AuditLogEntryInterface
    {
        return $this->entry;
    }

    /** The audited entity. */
    public function getSubject(): object
    {
        return $this->subject;
    }

    public function skip(): void
    {
        $this->skipped = true;
        $this->stopPropagation();
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
