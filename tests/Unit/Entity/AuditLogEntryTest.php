<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;

final class AuditLogEntryTest extends TestCase
{
    public function testDatesAreKeptInUtc(): void
    {
        $entry = new AuditLogEntry();
        $entry->setCreatedAt(new \DateTimeImmutable('2026-10-11 10:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertSame('2026-10-11T08:00:00+00:00', $entry->getCreatedAt()?->format(\DATE_ATOM));
    }

    public function testADateHydratedInTheDefaultTimezoneIsReadAsUtc(): void
    {
        $entry = new AuditLogEntry();
        // What Doctrine does with the stored "2026-10-11 08:00:00" when PHP runs in Paris.
        (new \ReflectionProperty(AuditLogEntry::class, 'createdAt'))->setValue($entry, new \DateTimeImmutable('2026-10-11 08:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertSame('2026-10-11T08:00:00+00:00', $entry->getCreatedAt()?->format(\DATE_ATOM));
    }

    public function testChangedFields(): void
    {
        $entry = new AuditLogEntry();
        $entry->setChanges(['name (en_US)' => ['old' => 'A', 'new' => 'B'], 'password' => ['masked' => true]]);

        self::assertSame(['name (en_US)', 'password'], $entry->getChangedFields());
    }
}
