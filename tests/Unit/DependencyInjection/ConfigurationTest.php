<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\TmonierSyliusAuditLogExtension;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertTrue($config['enabled']);
        self::assertSame(['admin', 'admin_api', 'cli'], $config['sources']);
        self::assertFalse($config['store_ip']);
        self::assertSame(365, $config['retention_days']);
        self::assertContains('password', $config['masked_fields']);
        self::assertContains('updatedAt', $config['ignored_fields']);
        self::assertContains('sylius:fixtures:load', $config['ignored_commands']);

        $resources = TmonierSyliusAuditLogExtension::mergeResources($config['resources']);
        self::assertSame(array_keys(Configuration::DEFAULT_RESOURCES), array_keys($resources));
        self::assertSame('product', $resources['product_variant']['owner']);
        self::assertSame(['lowestPriceBeforeDiscount', 'appliedPromotions'], $resources['channel_pricing']['ignored_fields']);
    }

    public function testResourcesCanBeAddedChangedAndDisabled(): void
    {
        $config = $this->process([
            'retention_days' => null,
            'sources' => ['admin', 'shop'],
            'resources' => [
                'tax_category' => ['class' => TaxCategoryInterface::class],
                'product' => ['ignored_fields' => ['position']],
                'order' => false,
                'customer' => ['enabled' => false],
            ],
        ]);

        self::assertNull($config['retention_days']);
        self::assertSame(['admin', 'shop'], $config['sources']);
        $resources = TmonierSyliusAuditLogExtension::mergeResources($config['resources']);
        self::assertSame(TaxCategoryInterface::class, $resources['tax_category']['class']);
        self::assertSame(ProductInterface::class, $resources['product']['class']);
        self::assertSame(['position'], $resources['product']['ignored_fields']);
        self::assertArrayNotHasKey('order', $resources);
        self::assertArrayNotHasKey('customer', $resources);
        // Payments and addresses lose their disabled owner and become their own roots.
        self::assertNull($resources['payment']['owner']);
        self::assertNull($resources['address']['owner']);
    }

    public function testAnUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['sources' => ['backoffice']]);
    }

    public function testANewResourceNeedsAnExistingClass(): void
    {
        $config = $this->process(['resources' => ['ghost' => ['class' => 'App\Entity\Ghost']]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"ghost"');

        TmonierSyliusAuditLogExtension::mergeResources($config['resources']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{enabled: bool, sources: list<string>, store_ip: bool, retention_days: ?int, masked_fields: list<string>, ignored_fields: list<string>, ignored_commands: list<string>, resources: array<string, array{enabled: bool, class: ?string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}>}
     */
    private function process(array $config): array
    {
        /** @var array{enabled: bool, sources: list<string>, store_ip: bool, retention_days: ?int, masked_fields: list<string>, ignored_fields: list<string>, ignored_commands: list<string>, resources: array<string, array{enabled: bool, class: ?string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}>} $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), ['tmonier_sylius_audit_log' => $config]);

        return $processed;
    }
}
