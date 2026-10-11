<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Maintenance;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Maintenance\CustomerAuditLogAnonymizer;

final class CustomerAuditLogAnonymizerTest extends TestCase
{
    public function testEntriesOfPersonalResourcesAreFullyAnonymized(): void
    {
        $entry = $this->entry('customer', 'jane@example.com', [
            'firstName' => ['old' => 'Jane', 'new' => 'Janet'],
            'group' => ['old' => null, 'new' => ['id' => '2', 'label' => 'Retail']],
            'subscribedToNewsletter' => ['old' => false, 'new' => true],
            'password' => ['masked' => true],
        ]);
        $entry->setIpAddress('203.0.113.0');

        $this->anonymizer()->anonymizeEntry($entry, 12, 'jane@example.com');

        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $entry->getResourceLabel());
        self::assertSame(['old' => CustomerAuditLogAnonymizer::PLACEHOLDER, 'new' => CustomerAuditLogAnonymizer::PLACEHOLDER], $entry->getChanges()['firstName']);
        self::assertSame(['old' => null, 'new' => ['id' => '2', 'label' => CustomerAuditLogAnonymizer::PLACEHOLDER]], $entry->getChanges()['group']);
        self::assertSame(['old' => CustomerAuditLogAnonymizer::PLACEHOLDER, 'new' => CustomerAuditLogAnonymizer::PLACEHOLDER], $entry->getChanges()['subscribedToNewsletter']);
        self::assertSame(['masked' => true], $entry->getChanges()['password']);
        self::assertNull($entry->getIpAddress());
    }

    public function testOrderEntriesOnlyLoseTheirPersonalFields(): void
    {
        $entry = $this->entry('order', '#000042', [
            'state' => ['old' => 'new', 'new' => 'cancelled'],
            'customer' => ['old' => null, 'new' => ['id' => '12', 'label' => 'jane@example.com']],
            'notes' => ['old' => null, 'new' => 'Call Jane at jane@example.com'],
            'shippingAddress' => ['old' => ['id' => '3', 'label' => 'Jane Doe, Lyon, FR'], 'new' => ['id' => '4', 'label' => 'Jane Doe, Paris, FR']],
            'currencyCode' => ['old' => 'USD', 'new' => 'EUR'],
        ]);
        $entry->setActorType(AuditLogEntryInterface::ACTOR_CUSTOMER);
        $entry->setActorId(12);
        $entry->setActorName('jane@example.com');

        $this->anonymizer()->anonymizeEntry($entry, 12, 'jane@example.com');

        self::assertSame('#000042', $entry->getResourceLabel());
        self::assertSame(['old' => 'new', 'new' => 'cancelled'], $entry->getChanges()['state']);
        self::assertSame(['old' => 'USD', 'new' => 'EUR'], $entry->getChanges()['currencyCode']);
        self::assertSame(['old' => null, 'new' => ['id' => '12', 'label' => CustomerAuditLogAnonymizer::PLACEHOLDER]], $entry->getChanges()['customer']);
        self::assertSame(['old' => null, 'new' => CustomerAuditLogAnonymizer::PLACEHOLDER], $entry->getChanges()['notes']);
        self::assertSame(['id' => '4', 'label' => CustomerAuditLogAnonymizer::PLACEHOLDER], $entry->getChanges()['shippingAddress']['new'] ?? null);
        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $entry->getActorName());
    }

    public function testTheEmailIsRemovedFromOtherValues(): void
    {
        $entry = $this->entry('payment', 'Bank transfer', [
            'description' => ['old' => null, 'new' => 'Refund to jane@example.com'],
        ]);

        $this->anonymizer()->anonymizeEntry($entry, 12, 'jane@example.com');

        self::assertSame('Refund to [anonymized]', $entry->getChanges()['description']['new'] ?? null);
    }

    /** @param array<string, array<string, mixed>> $changes */
    private function entry(string $resourceType, string $label, array $changes): AuditLogEntryInterface
    {
        $entry = new AuditLogEntry();
        $entry->setResourceType($resourceType);
        $entry->setResourceLabel($label);
        $entry->setActorType(AuditLogEntryInterface::ACTOR_ADMIN);
        $entry->setActorName('sylius');
        $entry->setChanges($changes);

        return $entry;
    }

    private function anonymizer(): CustomerAuditLogAnonymizer
    {
        return new CustomerAuditLogAnonymizer(
            $this->createMock(EntityManagerInterface::class),
            AuditLogEntry::class,
            Configuration::DEFAULT_PERSONAL_RESOURCES,
            Configuration::DEFAULT_PERSONAL_FIELDS,
        );
    }
}
