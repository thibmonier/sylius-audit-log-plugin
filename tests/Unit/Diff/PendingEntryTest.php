<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusAuditLogPlugin\Diff\ObjectReference;
use Tmonier\SyliusAuditLogPlugin\Diff\PendingEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

final class PendingEntryTest extends TestCase
{
    public function testAnUpdateWithoutChangesIsNotWorthRecordingButCreatesAndDeletesAre(): void
    {
        $update = $this->entry(AuditLogEntryInterface::ACTION_UPDATE);
        self::assertFalse($update->isWorthRecording());
        $update->setFieldChange('code', 'A', 'B');
        self::assertTrue($update->isWorthRecording());

        self::assertTrue($this->entry(AuditLogEntryInterface::ACTION_CREATE)->isWorthRecording());
        self::assertTrue($this->entry(AuditLogEntryInterface::ACTION_DELETE)->isWorthRecording());
    }

    public function testAReplacedCollectionOnlyKeepsTheRealDifferences(): void
    {
        $web = new ObjectReference(new \stdClass(), '1', 'Web');
        $mobile = new ObjectReference(new \stdClass(), '2', 'Mobile');
        $kiosk = new ObjectReference(new \stdClass(), '3', 'Kiosk');
        $entry = $this->entry(AuditLogEntryInterface::ACTION_UPDATE);

        // Old collection deleted, new one inserted: Web stays, Mobile goes, Kiosk comes.
        $entry->addCollectionChange('channels', [], [$web, $mobile]);
        $entry->addCollectionChange('channels', [new ObjectReference($web->object), $kiosk], []);

        $changes = $entry->finalizeChanges(static fn (ObjectReference $reference): array => ['id' => $reference->id, 'label' => $reference->label]);
        self::assertSame(['added' => [['id' => '3', 'label' => 'Kiosk']], 'removed' => [['id' => '2', 'label' => 'Mobile']]], $changes['channels']);
    }

    public function testACollectionChangeThatCancelsOutIsDropped(): void
    {
        $web = new ObjectReference(new \stdClass(), '1', 'Web');
        $entry = $this->entry(AuditLogEntryInterface::ACTION_UPDATE);

        $entry->addCollectionChange('channels', [$web], [$web]);

        self::assertFalse($entry->hasChanges());
    }

    public function testFinalizeResolvesReferencesInFieldChanges(): void
    {
        $taxon = new ObjectReference(new \stdClass());
        $entry = $this->entry(AuditLogEntryInterface::ACTION_UPDATE);
        $entry->setFieldChange('mainTaxon', null, $taxon);
        $entry->setMaskedField('password');

        $changes = $entry->finalizeChanges(static fn (ObjectReference $reference): array => ['id' => '7', 'label' => 'Caps']);

        self::assertSame(['old' => null, 'new' => ['id' => '7', 'label' => 'Caps']], $changes['mainTaxon']);
        self::assertSame(['masked' => true], $changes['password']);
    }

    private function entry(string $action): PendingEntry
    {
        return new PendingEntry($action, new AuditedResource('product', ProductInterface::class), new ObjectReference(new \stdClass()));
    }
}
