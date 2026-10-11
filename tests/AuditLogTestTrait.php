<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/**
 * Tests run against the test application loaded with the (random) Sylius fixtures. Every test
 * runs in a transaction rolled back at the end: audit entries are written inside it too.
 */
trait AuditLogTestTrait
{
    private function beginTestTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    private function rollBackTestTransaction(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function admin(string $username = 'sylius'): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(AdminUserInterface::class, $admin);

        return $admin;
    }

    private function authenticateAdmin(string $username = 'sylius'): AdminUserInterface
    {
        $admin = $this->admin($username);
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));

        return $admin;
    }

    /** A product with at least one variant priced in a channel, translated in en_US. */
    private function findProduct(): ProductInterface
    {
        /** @var ProductInterface|null $product */
        $product = $this->entityManager()
            ->createQueryBuilder()
            ->select('p')
            ->from(ProductInterface::class, 'p')
            ->innerJoin('p.variants', 'v')
            ->innerJoin('v.channelPricings', 'cp')
            ->innerJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->andWhere('SIZE(p.channels) > 0')
            ->setParameter('locale', 'en_US')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        self::assertNotNull($product, 'The fixtures contain priced products.');
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');

        return $product;
    }

    /** @return list<AuditLogEntryInterface> */
    private function findEntries(string $resourceType, int|string|null $resourceId = null): array
    {
        $criteria = ['resourceType' => $resourceType];
        if (null !== $resourceId) {
            $criteria['resourceId'] = (string) $resourceId;
        }

        /** @var list<AuditLogEntryInterface> $entries */
        $entries = $this->entityManager()->getRepository(AuditLogEntry::class)->findBy($criteria, ['id' => 'ASC']);

        return $entries;
    }

    private function countEntries(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_audit_log_entry');
    }

    private function idOf(object $resource): int
    {
        self::assertTrue(method_exists($resource, 'getId'));
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
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
