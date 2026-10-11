<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End to end through the real admin product form. The product form does not survive a
 * kernel kept between requests, so this test reboots it and cannot use the rolled-back
 * transaction: it restores the product name and deletes its entries itself.
 */
final class AdminProductEditTest extends WebTestCase
{
    private const NAME_FIELD = 'sylius_admin_product[translations][en_US][name]';

    public function testEditingAProductInTheAdminPanelRecordsOneCleanEntry(): void
    {
        $client = self::createClient();
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $client->loginUser($admin, 'admin');
        $productId = $this->connection()->fetchOne('SELECT MIN(p.id) FROM sylius_product p INNER JOIN sylius_product_translation t ON t.translatable_id = p.id AND t.locale = ?', ['en_US']);
        self::assertIsNumeric($productId);
        $lastId = $this->connection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM tmonier_audit_log_entry');
        self::assertIsNumeric($lastId);

        $oldName = $this->rename($client, (int) $productId, null);

        try {
            $this->rename($client, (int) $productId, $oldName . ' (edited)');
            self::assertResponseRedirects();

            $rows = $this->connection()->fetchAllAssociative('SELECT * FROM tmonier_audit_log_entry WHERE id > ?', [$lastId]);
            self::assertCount(1, $rows, 'Only the product changed');
            $row = $rows[0];
            self::assertSame('update', $row['action']);
            self::assertSame('product', $row['resource_type']);
            self::assertSame((string) $productId, $row['resource_id']);
            self::assertSame('sylius', $row['actor_name']);
            self::assertSame('admin', $row['source']);
            self::assertSame('sylius_admin_product_update', $row['route']);
            self::assertIsString($row['changes']);
            self::assertEquals(['name (en_US)' => ['old' => $oldName, 'new' => $oldName . ' (edited)']], json_decode($row['changes'], true));

            $crawler = $client->request('GET', sprintf('/admin/products/%d/edit', (int) $productId));
            self::assertStringContainsString('Name (en_US)', $crawler->filter('[data-test-audit-log-history]')->text());
        } finally {
            $this->rename($client, (int) $productId, $oldName);
            $this->connection()->executeStatement('DELETE FROM tmonier_audit_log_entry WHERE id > ?', [$lastId]);
        }
    }

    /** Submits the edit form with a new name (null: only reads it); returns the previous name. */
    private function rename(KernelBrowser $client, int $productId, ?string $name): string
    {
        $crawler = $client->request('GET', sprintf('/admin/products/%d/edit', $productId));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="sylius_admin_product"]')->form();
        $previous = $form[self::NAME_FIELD]->getValue();
        self::assertIsString($previous);

        if (null !== $name) {
            $form[self::NAME_FIELD] = $name;
            $client->submit($form);
        }

        return $previous;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
