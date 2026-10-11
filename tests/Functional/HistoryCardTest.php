<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Tmonier\SyliusAuditLogPlugin\AuditLogTestTrait;

final class HistoryCardTest extends WebTestCase
{
    use AuditLogTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTestTransaction();
        $this->client->loginUser($this->admin(), 'admin');
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testTheProductPagesShowTheChangesOfTheProductAndItsVariants(): void
    {
        $product = $this->findProduct();
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $product->setName('History hat');
        $variant->setOnHand((int) $variant->getOnHand() + 3);
        $this->entityManager()->flush();

        foreach (['/admin/products/%d', '/admin/products/%d/edit'] as $url) {
            $crawler = $this->client->request('GET', sprintf($url, $this->idOf($product)));
            self::assertResponseIsSuccessful();
            $card = $crawler->filter('[data-test-audit-log-history]');
            self::assertCount(1, $card, $url);
            self::assertCount(2, $card->filter('[data-test-audit-log-history-entry]'), $url);
            self::assertStringContainsString('Name (en_US)', $card->text(), $url);
            self::assertStringContainsString('On hand', $card->text(), $url);
            self::assertStringContainsString('Variant', $card->filter('[data-test-audit-log-history-resource]')->text(), $url);
            self::assertStringNotContainsString('tmonier_audit_log.', $card->text());
        }

        // "See all" opens the grid filtered on the product.
        $crawler = $this->client->request('GET', sprintf('/admin/products/%d', $this->idOf($product)));
        $this->client->click($crawler->filter('[data-test-audit-log-history-all]')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('[data-test-audit-log-resource-label]'));
    }

    public function testOrderCustomerAndPromotionPagesHaveAHistoryCard(): void
    {
        /** @var OrderInterface|null $order */
        $order = $this->entityManager()->getRepository(OrderInterface::class)->createQueryBuilder('o')
            ->innerJoin('o.customer', 'c')
            ->andWhere('o.checkoutState = :completed')
            ->setParameter('completed', 'completed')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertNotNull($order);
        $customer = $order->getCustomer();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $promotion = $this->entityManager()->getRepository(PromotionInterface::class)->findOneBy([]);
        self::assertInstanceOf(PromotionInterface::class, $promotion);

        $order->setNotes('History note');
        $customer->setPhoneNumber('+33 1 23 45 67 89');
        $promotion->setPriority($promotion->getPriority() + 1);
        $this->entityManager()->flush();

        foreach ([
            sprintf('/admin/orders/%d', $this->idOf($order)) => 'Notes',
            sprintf('/admin/customers/%d', $this->idOf($customer)) => 'Phone number',
            sprintf('/admin/promotions/%d/edit', $this->idOf($promotion)) => 'Priority',
        ] as $url => $field) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $card = $crawler->filter('[data-test-audit-log-history]');
            self::assertCount(1, $card, $url);
            self::assertStringContainsString($field, $card->text(), $url);
            self::assertSame(['Updated'], array_values(array_unique($card->filter('[data-test-audit-log-action]')->each(static fn (Crawler $node): string => trim($node->text())))), $url);
        }
    }

    public function testAResourceWithoutChangesShowsAnEmptyHistory(): void
    {
        $product = $this->findProduct();

        $crawler = $this->client->request('GET', sprintf('/admin/products/%d', $this->idOf($product)));

        self::assertResponseIsSuccessful();
        if (0 === count($this->findEntries('product', $this->idOf($product)))) {
            self::assertSelectorExists('[data-test-audit-log-history-empty]');
            self::assertCount(0, $crawler->filter('[data-test-audit-log-history-all]'));
        }
    }
}
