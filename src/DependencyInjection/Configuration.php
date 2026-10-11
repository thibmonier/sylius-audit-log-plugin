<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CatalogPromotionInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Promotion\Model\CatalogPromotionActionInterface;
use Sylius\Component\Promotion\Model\CatalogPromotionScopeInterface;
use Sylius\Component\Promotion\Model\PromotionActionInterface;
use Sylius\Component\Promotion\Model\PromotionRuleInterface;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Repository\AuditLogEntryRepository;

final class Configuration implements ConfigurationInterface
{
    /**
     * Audited resources by default. "owner" links a resource to the aggregate root whose history
     * card lists it (property path read on the audited object).
     */
    public const DEFAULT_RESOURCES = [
        'product' => ['class' => ProductInterface::class],
        'product_variant' => ['class' => ProductVariantInterface::class, 'owner' => 'product', 'owner_property_path' => 'product'],
        'channel_pricing' => [
            'class' => ChannelPricingInterface::class,
            'owner' => 'product_variant',
            'owner_property_path' => 'productVariant',
            'ignored_fields' => ['lowestPriceBeforeDiscount', 'appliedPromotions'],
        ],
        'taxon' => ['class' => TaxonInterface::class],
        'promotion' => ['class' => PromotionInterface::class],
        'promotion_coupon' => ['class' => PromotionCouponInterface::class, 'owner' => 'promotion', 'owner_property_path' => 'promotion'],
        'promotion_rule' => ['class' => PromotionRuleInterface::class, 'owner' => 'promotion', 'owner_property_path' => 'promotion'],
        'promotion_action' => ['class' => PromotionActionInterface::class, 'owner' => 'promotion', 'owner_property_path' => 'promotion'],
        'catalog_promotion' => ['class' => CatalogPromotionInterface::class],
        'catalog_promotion_scope' => ['class' => CatalogPromotionScopeInterface::class, 'owner' => 'catalog_promotion', 'owner_property_path' => 'catalogPromotion'],
        'catalog_promotion_action' => ['class' => CatalogPromotionActionInterface::class, 'owner' => 'catalog_promotion', 'owner_property_path' => 'catalogPromotion'],
        'shipping_method' => ['class' => ShippingMethodInterface::class],
        'payment_method' => ['class' => PaymentMethodInterface::class],
        'tax_rate' => ['class' => TaxRateInterface::class],
        'channel' => ['class' => ChannelInterface::class],
        'admin_user' => ['class' => AdminUserInterface::class],
        'customer' => ['class' => CustomerInterface::class],
        'address' => ['class' => AddressInterface::class, 'owner' => 'customer', 'owner_property_path' => 'customer'],
        'order' => ['class' => OrderInterface::class],
        'payment' => ['class' => PaymentInterface::class, 'owner' => 'order', 'owner_property_path' => 'order'],
        'shipment' => ['class' => ShipmentInterface::class, 'owner' => 'order', 'owner_property_path' => 'order'],
    ];

    public const DEFAULT_SOURCES = [
        AuditLogEntryInterface::SOURCE_ADMIN,
        AuditLogEntryInterface::SOURCE_ADMIN_API,
        AuditLogEntryInterface::SOURCE_CLI,
    ];

    public const DEFAULT_IGNORED_FIELDS = [
        'createdAt',
        'updatedAt',
        'lastLogin',
        'emailCanonical',
        'usernameCanonical',
        'version',
    ];

    public const DEFAULT_MASKED_FIELDS = [
        'password',
        'plainPassword',
        'salt',
        'tokenValue',
        'details',
        'config',
    ];

    public const DEFAULT_MASKED_FIELD_PATTERNS = [
        '/password/i',
        '/secret/i',
        '/token/i',
        '/salt/i',
        '/credential/i',
        '/api_?key/i',
    ];

    public const DEFAULT_PERSONAL_RESOURCES = ['customer', 'address'];

    public const DEFAULT_PERSONAL_FIELDS = [
        'email',
        'firstName',
        'lastName',
        'fullName',
        'phoneNumber',
        'birthday',
        'company',
        'street',
        'city',
        'postcode',
        'provinceCode',
        'provinceName',
        'customer',
        'customerIp',
        'billingAddress',
        'shippingAddress',
        'notes',
        'user',
        'username',
    ];

    public const DEFAULT_IGNORED_COMMANDS = [
        'sylius:fixtures:load',
        'doctrine:fixtures:load',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_audit_log');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Record changes. When false, nothing is recorded but the admin pages stay available.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('sources')
                    ->info('Where changes are recorded from: admin, admin_api, shop, shop_api, cli, other.')
                    ->enumPrototype()->values(AuditLogEntryInterface::SOURCES)->end()
                    ->defaultValue(self::DEFAULT_SOURCES)
                ->end()
                ->arrayNode('ignored_commands')
                    ->info('Console commands whose changes are never recorded.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_IGNORED_COMMANDS)
                ->end()
                ->arrayNode('resources')
                    ->info('Audited resources, merged with the defaults. Set a default resource to false (or enabled: false) to stop auditing it.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->canBeDisabled()
                        ->children()
                            ->scalarNode('class')->info('Class or interface of the audited entities.')->defaultNull()->end()
                            ->scalarNode('owner')->info('Resource key of the aggregate root (history card).')->defaultNull()->end()
                            ->scalarNode('owner_property_path')->info('Property path from the entity to its owner.')->defaultNull()->end()
                            ->arrayNode('ignored_fields')
                                ->scalarPrototype()->cannotBeEmpty()->end()
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('ignored_fields')
                    ->info('Fields never recorded, whatever the resource.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_IGNORED_FIELDS)
                ->end()
                ->arrayNode('masked_fields')
                    ->info('Fields whose values are never stored (only the fact that they changed). Case-insensitive.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_MASKED_FIELDS)
                ->end()
                ->arrayNode('masked_field_patterns')
                    ->info('Regular expressions: matching field names are masked too.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_MASKED_FIELD_PATTERNS)
                ->end()
                ->arrayNode('personal_resources')
                    ->info('Resource types whose entries are fully anonymized by tmonier:audit-log:anonymize-customer.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_PERSONAL_RESOURCES)
                ->end()
                ->arrayNode('personal_fields')
                    ->info('Fields anonymized in the other entries of the customer (orders, payments...).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_PERSONAL_FIELDS)
                ->end()
                ->integerNode('max_value_length')
                    ->info('Longer text values are truncated in the diff.')
                    ->min(16)
                    ->defaultValue(500)
                ->end()
                ->booleanNode('store_ip')
                    ->info('Store the IP address of the request.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('anonymize_ip')
                    ->info('When store_ip is on, zero the last byte (IPv4) or the last 80 bits (IPv6).')
                    ->defaultTrue()
                ->end()
                ->integerNode('retention_days')
                    ->info('Default age limit of tmonier:audit-log:purge. Null keeps entries forever.')
                    ->min(1)
                    ->defaultValue(365)
                ->end()
                ->integerNode('history_limit')
                    ->info('Number of entries shown in the history cards of the admin pages.')
                    ->min(1)
                    ->defaultValue(10)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('models')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('audit_log_entry')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(AuditLogEntry::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(AuditLogEntryInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                        ->scalarNode('repository')->defaultValue(AuditLogEntryRepository::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
