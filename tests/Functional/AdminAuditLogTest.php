<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Functional;

use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Tmonier\SyliusAuditLogPlugin\AuditLogTestTrait;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Recorder\AuditLogWriter;

final class AdminAuditLogTest extends WebTestCase
{
    use AuditLogTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testAChangeMadeInTheAdminPanelIsRecordedWithItsRequest(): void
    {
        $admin = $this->admin();
        $this->client->loginUser($admin, 'admin');
        $taxRate = $this->entityManager()->getRepository(TaxRateInterface::class)->findOneBy([]);
        self::assertInstanceOf(TaxRateInterface::class, $taxRate);
        $oldName = $taxRate->getName();

        $crawler = $this->client->request('GET', sprintf('/admin/tax-rates/%d/edit', $this->idOf($taxRate)));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="sylius_admin_tax_rate"]')->form();
        $form['sylius_admin_tax_rate[name]'] = 'Audited tax rate';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $entries = $this->findEntries('tax_rate', $this->idOf($taxRate));
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertEquals(['old' => $oldName, 'new' => 'Audited tax rate'], $entry->getChanges()['name']);
        self::assertSame(AuditLogEntryInterface::ACTOR_ADMIN, $entry->getActorType());
        self::assertSame($admin->getId(), $entry->getActorId());
        self::assertSame('sylius', $entry->getActorName());
        self::assertSame(AuditLogEntryInterface::SOURCE_ADMIN, $entry->getSource());
        self::assertSame('sylius_admin_tax_rate_update', $entry->getRoute());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $entry->getRequestId());
        self::assertNull($entry->getIpAddress(), 'IP addresses are not stored by default');

        // The entry is listed and shown.
        $crawler = $this->client->request('GET', '/admin/audit-log');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-audit-log-resource-label]', 'Audited tax rate');
        $this->client->click($crawler->filter(sprintf('a[href="/admin/audit-log/%d"]', (int) $entry->getId()))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test-audit-log-change="name"] [data-test-audit-log-old]', (string) $oldName);
        self::assertSelectorTextContains('[data-test-audit-log-change="name"] [data-test-audit-log-new]', 'Audited tax rate');
        self::assertSelectorTextContains('[data-test-audit-log-actor]', 'sylius');
        self::assertStringNotContainsString('tmonier_audit_log.', $this->client->getCrawler()->filter('body')->text());
    }

    public function testTheGridFiltersEntries(): void
    {
        $this->client->loginUser($this->admin(), 'admin');
        $this->write([
            $this->entry('product', '901', 'create', 'alice', '-3 days', ownerType: 'product', ownerId: '901', label: 'Filter hat'),
            $this->entry('product_variant', '902', 'update', 'bob', '-2 days', ownerType: 'product', ownerId: '901', label: 'Filter hat (S)'),
            $this->entry('taxon', '903', 'delete', 'alice', '-40 days', label: 'Filter caps'),
            $this->entry('order', '904', 'update', null, '-1 day', label: '#FILTER', requestId: 'filter-request-0001'),
        ]);

        $labels = $this->gridLabels(['resource' => ['type' => 'product', 'id' => '901']]);
        self::assertEqualsCanonicalizing(['Filter hat', 'Filter hat (S)'], $labels, 'A product and the resources it owns');

        self::assertSame(['Filter caps'], $this->gridLabels(['action' => 'delete', 'resource' => ['type' => 'taxon']]));
        self::assertEqualsCanonicalizing(['Filter hat', 'Filter caps'], $this->filterLabels($this->gridLabels(['actor' => ['type' => 'equal', 'value' => 'alice']])));
        self::assertSame(['#FILTER'], $this->gridLabels(['requestId' => ['value' => 'filter-request-0001']]));
        self::assertSame(['#FILTER'], $this->filterLabels($this->gridLabels(['actorType' => 'cli'])));

        $from = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $labels = $this->filterLabels($this->gridLabels(['createdAt' => ['from' => ['date' => $from, 'time' => '00:00']]]));
        self::assertNotContains('Filter caps', $labels);
        self::assertContains('Filter hat', $labels);
    }

    public function testTheShowPageRendersMaskedReferenceAndCollectionChanges(): void
    {
        $this->client->loginUser($this->admin(), 'admin');
        $entry = $this->entry('admin_user', '905', 'update', 'sylius', '-1 hour', label: 'jdoe');
        $entry->setChanges([
            'password' => ['masked' => true],
            'enabled' => ['old' => true, 'new' => false],
            'avatar' => ['old' => null, 'new' => ['id' => '7', 'label' => 'Avatar image']],
            'channels' => ['added' => [['id' => '1', 'label' => 'Web store']], 'removed' => [['id' => '2', 'label' => 'Mobile']]],
            'name (fr_FR)' => ['old' => 'Ancien', 'new' => 'Nouveau'],
        ]);
        $entry->setIpAddress('203.0.113.0');
        $this->write([$entry]);
        $id = (int) $this->findEntries('admin_user', '905')[0]->getId();

        $this->client->request('GET', sprintf('/admin/audit-log/%d', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test-audit-log-change="password"] [data-test-audit-log-masked]');
        self::assertSelectorTextContains('[data-test-audit-log-change="enabled"] [data-test-audit-log-new]', 'No');
        self::assertSelectorTextContains('[data-test-audit-log-change="avatar"] [data-test-audit-log-new]', 'Avatar image (#7)');
        self::assertSelectorTextContains('[data-test-audit-log-change="channels"] [data-test-audit-log-added]', 'Web store (#1)');
        self::assertSelectorTextContains('[data-test-audit-log-change="channels"] [data-test-audit-log-removed]', 'Mobile (#2)');
        self::assertSelectorTextContains('[data-test-audit-log-change="name (fr_FR)"] th', 'Name (fr_FR)');
        self::assertSelectorTextContains('body', '203.0.113.0');
        self::assertStringNotContainsString('tmonier_audit_log.', $this->client->getCrawler()->filter('body')->text());
    }

    public function testTheMenuLinksToTheAuditLog(): void
    {
        $this->client->loginUser($this->admin(), 'admin');

        $crawler = $this->client->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/audit-log"]'));
    }

    public function testOnlyAdministratorsCanReadTheAuditLog(): void
    {
        $this->client->request('GET', '/admin/audit-log');
        self::assertResponseRedirects('http://localhost/admin/login');

        $shopUser = $this->entityManager()->getRepository(ShopUserInterface::class)->findOneBy([]);
        self::assertInstanceOf(ShopUserInterface::class, $shopUser);
        $this->client->loginUser($shopUser, 'shop');
        $this->client->request('GET', '/admin/audit-log/1');
        self::assertResponseRedirects('http://localhost/admin/login');
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<string>
     */
    private function gridLabels(array $criteria): array
    {
        $crawler = $this->client->request('GET', '/admin/audit-log?' . http_build_query(['criteria' => $criteria, 'limit' => 100]));
        self::assertResponseIsSuccessful();

        /** @var list<string> $labels */
        $labels = $crawler->filter('[data-test-audit-log-resource-label]')->each(static fn (Crawler $node): string => trim($node->text()));

        return $labels;
    }

    /**
     * Keeps the entries written by the test (the database may hold others).
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function filterLabels(array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => str_contains($label, 'Filter') || str_contains($label, 'FILTER')));
    }

    private function entry(
        string $type,
        string $id,
        string $action,
        ?string $actor,
        string $age,
        ?string $ownerType = null,
        ?string $ownerId = null,
        ?string $label = null,
        ?string $requestId = null,
    ): AuditLogEntryInterface {
        $entry = new AuditLogEntry();
        $entry->setCreatedAt(new \DateTimeImmutable($age, new \DateTimeZone('UTC')));
        $entry->setAction($action);
        $entry->setResourceType($type);
        $entry->setResourceClass(\stdClass::class);
        $entry->setResourceId($id);
        $entry->setResourceLabel($label);
        $entry->setOwnerType($ownerType ?? $type);
        $entry->setOwnerId($ownerId ?? $id);
        $entry->setChanges(['code' => ['old' => 'A', 'new' => 'B']]);
        $entry->setActorType(null !== $actor ? AuditLogEntryInterface::ACTOR_ADMIN : AuditLogEntryInterface::ACTOR_CLI);
        $entry->setActorName($actor);
        $entry->setSource(null !== $actor ? AuditLogEntryInterface::SOURCE_ADMIN : AuditLogEntryInterface::SOURCE_CLI);
        $entry->setRequestId($requestId ?? bin2hex(random_bytes(16)));

        return $entry;
    }

    /** @param list<AuditLogEntryInterface> $entries */
    private function write(array $entries): void
    {
        (new AuditLogWriter())->write($this->entityManager(), $entries);
    }
}
