<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Integration;

use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\Tmonier\SyliusAuditLogPlugin\AuditLogTestTrait;
use Tmonier\SyliusAuditLogPlugin\Diff\ValueNormalizer;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Event\AuditLogEntryEvent;

final class RecordingTest extends KernelTestCase
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

    public function testAProductUpdateRecordsTranslatedFieldsAndTheAdministrator(): void
    {
        $admin = $this->authenticateAdmin();
        $product = $this->findProduct();
        $oldName = $product->getName();
        $wasEnabled = $product->isEnabled();

        $product->setName('Audited hat');
        $product->setEnabled(!$wasEnabled);
        $product->setUpdatedAt(new \DateTime('+1 day'));
        $this->entityManager()->flush();

        $entries = $this->findEntries('product', $this->idOf($product));
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(AuditLogEntryInterface::ACTION_UPDATE, $entry->getAction());
        self::assertEquals(['old' => $oldName, 'new' => 'Audited hat'], $entry->getChanges()['name (en_US)']);
        self::assertEquals(['old' => $wasEnabled, 'new' => !$wasEnabled], $entry->getChanges()['enabled']);
        self::assertArrayNotHasKey('updatedAt', $entry->getChanges());
        self::assertStringContainsString('Audited hat', (string) $entry->getResourceLabel());
        self::assertSame('product', $entry->getOwnerType());
        self::assertSame((string) $this->idOf($product), $entry->getOwnerId());
        self::assertSame(AuditLogEntryInterface::ACTOR_ADMIN, $entry->getActorType());
        self::assertSame($admin->getId(), $entry->getActorId());
        self::assertSame('sylius', $entry->getActorName());
        self::assertSame(AuditLogEntryInterface::SOURCE_CLI, $entry->getSource());
        self::assertNotNull($entry->getRequestId());
        self::assertEquals(new \DateTimeZone('UTC'), $entry->getCreatedAt()?->getTimezone());
        self::assertEqualsWithDelta(time(), $entry->getCreatedAt()?->getTimestamp(), 120, 'Stored in UTC');
    }

    public function testPriceAndStockChangesBelongToTheProduct(): void
    {
        $product = $this->findProduct();
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $pricing = $variant->getChannelPricings()->first();
        self::assertNotFalse($pricing);
        $oldPrice = $pricing->getPrice();
        $oldOnHand = $variant->getOnHand();

        $pricing->setPrice((int) $oldPrice + 1000);
        $variant->setOnHand((int) $oldOnHand + 5);
        $this->entityManager()->flush();

        $pricingEntries = $this->findEntries('channel_pricing', $this->idOf($pricing));
        self::assertCount(1, $pricingEntries);
        self::assertEquals(['old' => $oldPrice, 'new' => (int) $oldPrice + 1000], $pricingEntries[0]->getChanges()['price']);
        self::assertSame('product', $pricingEntries[0]->getOwnerType());
        self::assertSame((string) $this->idOf($product), $pricingEntries[0]->getOwnerId());
        self::assertStringContainsString((string) $pricing->getChannelCode(), (string) $pricingEntries[0]->getResourceLabel());

        $variantEntries = $this->findEntries('product_variant', $this->idOf($variant));
        self::assertCount(1, $variantEntries);
        self::assertEquals(['old' => $oldOnHand, 'new' => (int) $oldOnHand + 5], $variantEntries[0]->getChanges()['onHand']);
        self::assertSame((string) $this->idOf($product), $variantEntries[0]->getOwnerId());
        self::assertSame($pricingEntries[0]->getRequestId(), $variantEntries[0]->getRequestId(), 'Same flush, same correlation id');
    }

    public function testCreationAndDeletionKeepTheIdLabelAndValues(): void
    {
        /** @var FactoryInterface<TaxRateInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.tax_rate');
        $category = $this->entityManager()->getRepository(TaxCategoryInterface::class)->findOneBy([]);
        $zone = $this->entityManager()->getRepository(ZoneInterface::class)->findOneBy([]);
        self::assertNotNull($category);
        self::assertNotNull($zone);

        $taxRate = $factory->createNew();
        $taxRate->setCode('AUDIT_VAT');
        $taxRate->setName('Audit VAT');
        $taxRate->setAmount(0.2);
        $taxRate->setCalculator('default');
        $taxRate->setCategory($category);
        $taxRate->setZone($zone);
        $this->entityManager()->persist($taxRate);
        $this->entityManager()->flush();
        $id = $this->idOf($taxRate);

        $taxRate->setAmount(0.21);
        $this->entityManager()->flush();

        $this->entityManager()->remove($taxRate);
        $this->entityManager()->flush();

        $entries = $this->findEntries('tax_rate', $id);
        self::assertSame([AuditLogEntryInterface::ACTION_CREATE, AuditLogEntryInterface::ACTION_UPDATE, AuditLogEntryInterface::ACTION_DELETE], array_map(static fn (AuditLogEntryInterface $entry): ?string => $entry->getAction(), $entries));
        [$create, $update, $delete] = $entries;

        self::assertSame('Audit VAT (AUDIT_VAT)', $create->getResourceLabel());
        self::assertEquals(['old' => null, 'new' => 'AUDIT_VAT'], $create->getChanges()['code']);
        self::assertEquals(['old' => null, 'new' => 0.2], $create->getChanges()['amount']);
        self::assertEquals(['old' => null, 'new' => ['id' => (string) $this->idOf($category), 'label' => sprintf('%s (%s)', $category->getName(), $category->getCode())]], $create->getChanges()['category']);
        self::assertEquals(['amount' => ['old' => 0.2, 'new' => 0.21]], $update->getChanges());
        self::assertSame('Audit VAT (AUDIT_VAT)', $delete->getResourceLabel());
        self::assertEquals(['old' => 'AUDIT_VAT', 'new' => null], $delete->getChanges()['code']);
        self::assertEquals(['old' => 0.21, 'new' => null], $delete->getChanges()['amount']);
        self::assertSame($taxRate::class, $delete->getResourceClass());
    }

    public function testADecimalSetToTheSameNumberIsNotAChange(): void
    {
        $taxRate = $this->entityManager()->getRepository(TaxRateInterface::class)->findOneBy([]);
        self::assertNotNull($taxRate);
        $this->entityManager()->refresh($taxRate);
        $before = $this->countEntries();

        $taxRate->setAmount((float) $taxRate->getAmount());
        $this->entityManager()->flush();

        self::assertSame($before, $this->countEntries());
    }

    public function testManyToManyCollectionChangesAreRecorded(): void
    {
        $product = $this->findProduct();
        $removed = $product->getChannels()->first();
        self::assertInstanceOf(ChannelInterface::class, $removed);
        $product->removeChannel($removed);
        $this->entityManager()->flush();

        $entries = $this->findEntries('product', $this->idOf($product));
        self::assertCount(1, $entries);
        self::assertSame([
            'added' => [],
            'removed' => [['id' => (string) $this->idOf($removed), 'label' => sprintf('%s (%s)', $removed->getName(), $removed->getCode())]],
        ], $entries[0]->getChanges()['channels']);
    }

    public function testNothingIsRecordedForIgnoredFieldsOrUnauditedEntities(): void
    {
        $product = $this->findProduct();
        $before = $this->countEntries();

        $product->setUpdatedAt(new \DateTime('+2 days'));
        $category = $this->entityManager()->getRepository(TaxCategoryInterface::class)->findOneBy([]);
        self::assertNotNull($category);
        $category->setDescription('Not audited by default');
        $this->entityManager()->flush();

        self::assertSame($before, $this->countEntries());
    }

    public function testSecretsAreMaskedAndLongTextsTruncated(): void
    {
        $admin = $this->admin();
        $oldHash = (string) $admin->getPassword();
        $admin->setPassword('$argon2id$v=19$new-hash');
        $admin->setPasswordResetToken('reset-token-value');
        $product = $this->findProduct();
        $product->setDescription(str_repeat('Long description. ', 100));
        $this->entityManager()->flush();

        $adminEntry = $this->findEntries('admin_user', $this->idOf($admin))[0];
        self::assertSame(['masked' => true], $adminEntry->getChanges()['password']);
        self::assertSame(['masked' => true], $adminEntry->getChanges()['passwordResetToken']);
        $raw = $this->connection()->fetchOne('SELECT changes FROM tmonier_audit_log_entry WHERE id = ?', [$adminEntry->getId()]);
        self::assertIsString($raw);
        self::assertStringNotContainsString('new-hash', $raw);
        self::assertStringNotContainsString('reset-token-value', $raw);
        self::assertStringNotContainsString($oldHash, $raw);

        $description = $this->findEntries('product', $this->idOf($product))[0]->getChanges()['description (en_US)']['new'] ?? null;
        self::assertIsString($description);
        self::assertSame(500, mb_strlen($description));
        self::assertStringEndsWith(ValueNormalizer::ELLIPSIS, $description);
    }

    public function testCommandLineChangesNameTheCommandAndIgnoredCommandsAreNotRecorded(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $product = $this->findProduct();

        $command = new Command('app:import-catalog');
        $dispatcher->dispatch(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
        $product->setCode($product->getCode() . '_IMPORTED');
        $this->entityManager()->flush();
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);

        $entry = $this->findEntries('product', $this->idOf($product))[0];
        self::assertSame(AuditLogEntryInterface::ACTOR_CLI, $entry->getActorType());
        self::assertNull($entry->getActorName());
        self::assertSame(AuditLogEntryInterface::SOURCE_CLI, $entry->getSource());
        self::assertSame('app:import-catalog', $entry->getRoute());

        $before = $this->countEntries();
        $fixtures = new Command('sylius:fixtures:load');
        $dispatcher->dispatch(new ConsoleCommandEvent($fixtures, new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
        $product->setCode($product->getCode() . '_AGAIN');
        $this->entityManager()->flush();
        $dispatcher->dispatch(new ConsoleTerminateEvent($fixtures, new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        self::assertSame($before, $this->countEntries());
    }

    public function testOrderStateAndAddressChangesBelongToTheOrder(): void
    {
        $this->authenticateAdmin();
        /** @var OrderInterface|null $order */
        $order = $this->entityManager()->getRepository(OrderInterface::class)->createQueryBuilder('o')
            ->innerJoin('o.billingAddress', 'a')
            ->andWhere('o.checkoutState = :completed')
            ->setParameter('completed', 'completed')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertNotNull($order, 'The fixtures contain placed orders.');
        $address = $order->getBillingAddress();
        self::assertNotNull($address);

        $order->setNotes('Customer called, deliver after 6 pm');
        $address->setStreet('1 Audit Street');
        $this->entityManager()->flush();

        $orderEntry = $this->findEntries('order', $this->idOf($order))[0];
        self::assertSame('#' . $order->getNumber(), $orderEntry->getResourceLabel());
        self::assertSame('Customer called, deliver after 6 pm', $orderEntry->getChanges()['notes']['new'] ?? null);

        $addressEntry = $this->findEntries('address', $this->idOf($address))[0];
        self::assertSame('1 Audit Street', $addressEntry->getChanges()['street']['new'] ?? null);
        if (null === $address->getCustomer()) {
            self::assertSame('order', $addressEntry->getOwnerType());
            self::assertSame((string) $this->idOf($order), $addressEntry->getOwnerId());
        } else {
            self::assertSame('customer', $addressEntry->getOwnerType());
        }
    }

    public function testListenersCanSkipOrEnrichEntries(): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $listener = static function (AuditLogEntryEvent $event): void {
            if ($event->getSubject() instanceof AdminUserInterface) {
                $event->skip();

                return;
            }
            $event->getEntry()->setRoute('enriched');
        };
        $dispatcher->addListener(AuditLogEntryEvent::PRE_WRITE, $listener);

        try {
            $admin = $this->admin();
            $admin->setFirstName('Skipped');
            $product = $this->findProduct();
            $product->setName('Enriched hat');
            $this->entityManager()->flush();
        } finally {
            $dispatcher->removeListener(AuditLogEntryEvent::PRE_WRITE, $listener);
        }

        self::assertSame([], $this->findEntries('admin_user', $this->idOf($admin)));
        self::assertSame('enriched', $this->findEntries('product', $this->idOf($product))[0]->getRoute());
    }

    public function testANestedFlushKeepsItsOwnChanges(): void
    {
        $product = $this->findProduct();
        $admin = $this->admin();
        $entityManager = $this->entityManager();

        $nested = new class($entityManager, $admin) {
            private bool $done = false;

            public function __construct(private readonly \Doctrine\ORM\EntityManagerInterface $entityManager, private readonly AdminUserInterface $admin)
            {
            }

            public function postFlush(): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->admin->setLastName('Nested');
                $this->entityManager->flush();
            }
        };
        // Registered before the recorder's postFlush: the nested flush runs first.
        $entityManager->getEventManager()->addEventListener('postFlush', $nested);

        try {
            $product->setName('Outer change');
            $entityManager->flush();
        } finally {
            $entityManager->getEventManager()->removeEventListener('postFlush', $nested);
        }

        $productEntries = $this->findEntries('product', $this->idOf($product));
        $adminEntries = $this->findEntries('admin_user', $this->idOf($admin));
        self::assertCount(1, $productEntries);
        self::assertArrayHasKey('name (en_US)', $productEntries[0]->getChanges());
        self::assertArrayNotHasKey('lastName', $productEntries[0]->getChanges());
        self::assertCount(1, $adminEntries);
        self::assertSame(['lastName'], $adminEntries[0]->getChangedFields());
    }
}
