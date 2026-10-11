<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Integration;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusAuditLogPlugin\AuditLogTestTrait;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Maintenance\CustomerAuditLogAnonymizer;
use Tmonier\SyliusAuditLogPlugin\Recorder\AuditLogWriter;

final class MaintenanceCommandsTest extends KernelTestCase
{
    use AuditLogTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testPurgeDeletesEntriesOlderThanTheLimit(): void
    {
        $this->write([
            $this->entry('product', '1', '-400 days'),
            $this->entry('product', '2', '-40 days'),
            $this->entry('product', '3', '-1 day'),
        ]);
        $total = $this->countEntries();
        $tester = $this->command('tmonier:audit-log:purge');

        $tester->execute(['--older-than' => '30d', '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 entr(y|ies) created before', $tester->getDisplay());
        self::assertSame($total, $this->countEntries());

        // Default limit: retention_days (365).
        $tester->execute(['--batch-size' => '1']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 entr(y|ies)', $tester->getDisplay());
        self::assertSame([], $this->findEntries('product', '1'));

        $tester->execute(['--older-than' => '1 month']);
        self::assertStringContainsString('Deleted 1 entr(y|ies)', $tester->getDisplay());
        self::assertSame([], $this->findEntries('product', '2'));
        self::assertCount(1, $this->findEntries('product', '3'));

        $tester->execute(['--older-than' => 'last week']);
        self::assertSame(2, $tester->getStatusCode(), 'Invalid input');
    }

    public function testAnonymizeRemovesThePersonalDataOfACustomer(): void
    {
        /** @var OrderInterface|null $order */
        $order = $this->entityManager()->getRepository(OrderInterface::class)->createQueryBuilder('o')
            ->innerJoin('o.customer', 'c')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertNotNull($order);
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $email = (string) $customer->getEmail();
        $customerId = (string) $customer->getId();

        $this->authenticateAdmin();
        $customer->setFirstName('Erasable');
        $customer->setPhoneNumber('+33 6 00 00 00 00');
        $order->setNotes(sprintf('Contact %s', $email));
        $this->entityManager()->flush();
        $other = $this->entry('product', '1', '-1 day');
        $other->setChanges(['name (en_US)' => ['old' => 'Hat', 'new' => 'Cap']]);
        $this->write([$other]);

        $tester = $this->command('tmonier:audit-log:anonymize-customer');
        $tester->execute(['customer' => $email, '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(sprintf('2 entr(y|ies) of customer #%s would be anonymized', $customerId), $tester->getDisplay());

        $tester->execute(['customer' => $email]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Anonymized 2 entr(y|ies)', $tester->getDisplay());

        $this->entityManager()->clear();
        $customerEntry = $this->findEntries('customer', $customerId)[0];
        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $customerEntry->getResourceLabel());
        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $customerEntry->getChanges()['firstName']['new'] ?? null);
        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $customerEntry->getChanges()['phoneNumber']['new'] ?? null);
        $orderEntry = $this->findEntries('order', (string) $order->getId())[0];
        self::assertSame('#' . $order->getNumber(), $orderEntry->getResourceLabel());
        self::assertSame(CustomerAuditLogAnonymizer::PLACEHOLDER, $orderEntry->getChanges()['notes']['new'] ?? null);
        self::assertSame('sylius', $orderEntry->getActorName(), 'The administrator stays known');
        self::assertSame('Cap', $this->findEntries('product', '1')[0]->getChanges()['name (en_US)']['new'] ?? null, 'Other entries are untouched');

        $tester->execute(['customer' => 'nobody@example.invalid']);
        self::assertSame(1, $tester->getStatusCode());
    }

    private function entry(string $type, string $id, string $age): AuditLogEntryInterface
    {
        $entry = new AuditLogEntry();
        $entry->setCreatedAt(new \DateTimeImmutable($age, new \DateTimeZone('UTC')));
        $entry->setAction(AuditLogEntryInterface::ACTION_UPDATE);
        $entry->setResourceType($type);
        $entry->setResourceClass(\stdClass::class);
        $entry->setResourceId($id);
        $entry->setOwnerType($type);
        $entry->setOwnerId($id);
        $entry->setActorType(AuditLogEntryInterface::ACTOR_SYSTEM);
        $entry->setSource(AuditLogEntryInterface::SOURCE_OTHER);

        return $entry;
    }

    /** @param list<AuditLogEntryInterface> $entries */
    private function write(array $entries): void
    {
        (new AuditLogWriter())->write($this->entityManager(), $entries);
    }

    private function command(string $name): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find($name));
    }
}
