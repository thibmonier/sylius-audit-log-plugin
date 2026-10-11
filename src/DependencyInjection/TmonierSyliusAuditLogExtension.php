<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusAuditLogPlugin\Resolver\LabelResolverInterface;
use Tmonier\SyliusAuditLogPlugin\Resolver\OwnerResolverInterface;

final class TmonierSyliusAuditLogExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /**
         * @var array{
         *     enabled: bool,
         *     sources: list<string>,
         *     ignored_commands: list<string>,
         *     resources: array<string, array{enabled: bool, class: ?string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}>,
         *     ignored_fields: list<string>,
         *     masked_fields: list<string>,
         *     masked_field_patterns: list<string>,
         *     personal_resources: list<string>,
         *     personal_fields: list<string>,
         *     max_value_length: int,
         *     store_ip: bool,
         *     anonymize_ip: bool,
         *     retention_days: ?int,
         *     history_limit: int,
         *     driver: string,
         *     models: array<string, mixed>,
         * } $config
         */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_audit_log', $config['driver'], $config['models'], $container);

        $container->setParameter('tmonier_audit_log.enabled', $config['enabled']);
        $container->setParameter('tmonier_audit_log.sources', array_values(array_unique($config['sources'])));
        $container->setParameter('tmonier_audit_log.ignored_commands', array_values(array_unique($config['ignored_commands'])));
        $container->setParameter('tmonier_audit_log.resources', self::mergeResources($config['resources']));
        $container->setParameter('tmonier_audit_log.ignored_fields', array_values(array_unique($config['ignored_fields'])));
        $container->setParameter('tmonier_audit_log.masked_fields', array_values(array_unique($config['masked_fields'])));
        $container->setParameter('tmonier_audit_log.masked_field_patterns', array_values(array_unique($config['masked_field_patterns'])));
        $container->setParameter('tmonier_audit_log.personal_resources', array_values(array_unique($config['personal_resources'])));
        $container->setParameter('tmonier_audit_log.personal_fields', array_values(array_unique($config['personal_fields'])));
        $container->setParameter('tmonier_audit_log.max_value_length', $config['max_value_length']);
        $container->setParameter('tmonier_audit_log.store_ip', $config['store_ip']);
        $container->setParameter('tmonier_audit_log.anonymize_ip', $config['anonymize_ip']);
        $container->setParameter('tmonier_audit_log.retention_days', $config['retention_days']);
        $container->setParameter('tmonier_audit_log.history_limit', $config['history_limit']);

        $container->registerForAutoconfiguration(LabelResolverInterface::class)->addTag('tmonier_audit_log.label_resolver');
        $container->registerForAutoconfiguration(OwnerResolverInterface::class)->addTag('tmonier_audit_log.owner_resolver');

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded, so the model class parameter
        // has to exist at prepend time.
        /** @var array{models: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['models'] as $name => $model) {
            $container->setParameter(sprintf('tmonier_audit_log.model.%s.class', $name), $model['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusAuditLogPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusAuditLogPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    /**
     * @param array<string, array{enabled: bool, class: ?string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}> $resources
     *
     * @return array<string, array{class: string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}>
     */
    public static function mergeResources(array $resources): array
    {
        $merged = [];
        foreach (Configuration::DEFAULT_RESOURCES as $name => $default) {
            $merged[$name] = [
                'class' => $default['class'],
                'owner' => $default['owner'] ?? null,
                'owner_property_path' => $default['owner_property_path'] ?? null,
                'ignored_fields' => $default['ignored_fields'] ?? [],
            ];
        }

        foreach ($resources as $name => $resource) {
            if (!$resource['enabled']) {
                unset($merged[$name]);

                continue;
            }

            $base = $merged[$name] ?? ['class' => null, 'owner' => null, 'owner_property_path' => null, 'ignored_fields' => []];
            $merged[$name] = [
                'class' => $resource['class'] ?? $base['class'],
                'owner' => $resource['owner'] ?? $base['owner'],
                'owner_property_path' => $resource['owner_property_path'] ?? $base['owner_property_path'],
                'ignored_fields' => array_values(array_unique([...$base['ignored_fields'], ...$resource['ignored_fields']])),
            ];
        }

        foreach ($merged as $name => $resource) {
            $class = $resource['class'];
            if (null === $class || (!class_exists($class) && !interface_exists($class))) {
                throw new InvalidConfigurationException(sprintf('The audited resource "%s" needs an existing "class" (class or interface), "%s" given.', $name, (string) $class));
            }

            $owner = $resource['owner'];
            if (null !== $owner && (!isset($merged[$owner]) || null === $resource['owner_property_path'])) {
                // The owner is not audited (disabled by the user): the resource becomes its own root.
                $resource['owner'] = null;
                $resource['owner_property_path'] = null;
            }

            $merged[$name] = ['class' => $class] + $resource;
        }

        /** @var array<string, array{class: string, owner: ?string, owner_property_path: ?string, ignored_fields: list<string>}> $merged */
        return $merged;
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusAuditLogPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusAuditLogPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
