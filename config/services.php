<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Console\ConsoleEvents;
use Tmonier\SyliusAuditLogPlugin\Command\AnonymizeCustomerAuditLogCommand;
use Tmonier\SyliusAuditLogPlugin\Command\PurgeAuditLogCommand;
use Tmonier\SyliusAuditLogPlugin\Context\AuditContextProvider;
use Tmonier\SyliusAuditLogPlugin\Context\AuditContextProviderInterface;
use Tmonier\SyliusAuditLogPlugin\Context\RecordingPolicy;
use Tmonier\SyliusAuditLogPlugin\Diff\FieldPolicy;
use Tmonier\SyliusAuditLogPlugin\Diff\ValueNormalizer;
use Tmonier\SyliusAuditLogPlugin\Form\Type\ResourceFilterType;
use Tmonier\SyliusAuditLogPlugin\Grid\ResourceFilter;
use Tmonier\SyliusAuditLogPlugin\Maintenance\CustomerAuditLogAnonymizer;
use Tmonier\SyliusAuditLogPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusAuditLogPlugin\Recorder\AuditLogRecorder;
use Tmonier\SyliusAuditLogPlugin\Recorder\AuditLogWriter;
use Tmonier\SyliusAuditLogPlugin\Recorder\ChangeCollector;
use Tmonier\SyliusAuditLogPlugin\Repository\AuditLogEntryRepositoryInterface;
use Tmonier\SyliusAuditLogPlugin\Resolver\CompositeLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\CompositeOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\CoreLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\GenericLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\OrderAddressOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\PropertyPathOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistry;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistryInterface;
use Tmonier\SyliusAuditLogPlugin\Twig\AuditLogExtension;
use Tmonier\SyliusAuditLogPlugin\Twig\AuditLogPresenter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias(AuditLogEntryRepositoryInterface::class, 'tmonier_audit_log.repository.audit_log_entry');

    $services
        ->set('tmonier_audit_log.resource_registry', AuditedResourceRegistry::class)
        ->args([param('tmonier_audit_log.resources')])
    ;
    $services->alias(AuditedResourceRegistryInterface::class, 'tmonier_audit_log.resource_registry');

    // Labels and owners (extension points: tag your own resolvers, higher priority first)
    $services
        ->set('tmonier_audit_log.label_resolver.core', CoreLabelResolver::class)
        ->tag('tmonier_audit_log.label_resolver', ['priority' => 0])
    ;
    $services
        ->set('tmonier_audit_log.label_resolver.generic', GenericLabelResolver::class)
        ->tag('tmonier_audit_log.label_resolver', ['priority' => -1000])
    ;
    $services
        ->set('tmonier_audit_log.label_resolver', CompositeLabelResolver::class)
        ->args([tagged_iterator('tmonier_audit_log.label_resolver')])
    ;

    $services
        ->set('tmonier_audit_log.owner_resolver.order_address', OrderAddressOwnerResolver::class)
        ->tag('tmonier_audit_log.owner_resolver', ['priority' => 100])
    ;
    $services
        ->set('tmonier_audit_log.owner_resolver.property_path', PropertyPathOwnerResolver::class)
        ->args([service('property_accessor')])
        ->tag('tmonier_audit_log.owner_resolver', ['priority' => -1000])
    ;
    $services
        ->set('tmonier_audit_log.owner_resolver', CompositeOwnerResolver::class)
        ->args([
            tagged_iterator('tmonier_audit_log.owner_resolver'),
            service('tmonier_audit_log.resource_registry'),
        ])
    ;

    // Recording
    $services
        ->set('tmonier_audit_log.context_provider', AuditContextProvider::class)
        ->args([
            service('request_stack'),
            service('security.token_storage'),
            service('sylius.section_resolver.uri_based')->nullOnInvalid(),
            param('tmonier_audit_log.store_ip'),
            param('tmonier_audit_log.anonymize_ip'),
        ])
        ->tag('kernel.event_listener', ['event' => ConsoleEvents::COMMAND, 'method' => 'onConsoleCommand'])
        ->tag('kernel.event_listener', ['event' => ConsoleEvents::TERMINATE, 'method' => 'onConsoleTerminate'])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(AuditContextProviderInterface::class, 'tmonier_audit_log.context_provider');

    $services
        ->set('tmonier_audit_log.recording_policy', RecordingPolicy::class)
        ->args([
            service('tmonier_audit_log.context_provider'),
            param('tmonier_audit_log.enabled'),
            param('tmonier_audit_log.sources'),
            param('tmonier_audit_log.ignored_commands'),
        ])
    ;

    $services
        ->set('tmonier_audit_log.field_policy', FieldPolicy::class)
        ->args([
            param('tmonier_audit_log.ignored_fields'),
            param('tmonier_audit_log.masked_fields'),
            param('tmonier_audit_log.masked_field_patterns'),
        ])
    ;

    $services
        ->set('tmonier_audit_log.value_normalizer', ValueNormalizer::class)
        ->args([param('tmonier_audit_log.max_value_length')])
    ;

    $services
        ->set('tmonier_audit_log.change_collector', ChangeCollector::class)
        ->args([
            service('tmonier_audit_log.resource_registry'),
            service('tmonier_audit_log.owner_resolver'),
            service('tmonier_audit_log.label_resolver'),
            service('tmonier_audit_log.value_normalizer'),
            service('tmonier_audit_log.field_policy'),
        ])
    ;

    $services->set('tmonier_audit_log.writer', AuditLogWriter::class);

    $services
        ->set('tmonier_audit_log.recorder', AuditLogRecorder::class)
        ->args([
            service('tmonier_audit_log.change_collector'),
            service('tmonier_audit_log.writer'),
            service('tmonier_audit_log.context_provider'),
            service('tmonier_audit_log.recording_policy'),
            service('tmonier_audit_log.label_resolver'),
            service('tmonier_audit_log.factory.audit_log_entry'),
            service('event_dispatcher'),
            service('clock'),
            service('logger'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush'])
        ->tag('kernel.reset', ['method' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'tmonier_audit_log'])
    ;

    // Back office
    $services
        ->set('tmonier_audit_log.admin_menu_listener', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;

    $services
        ->set('tmonier_audit_log.presenter', AuditLogPresenter::class)
        ->args([
            service('translator'),
            service('router'),
        ])
    ;

    $services
        ->set('tmonier_audit_log.twig.extension', AuditLogExtension::class)
        ->args([
            service('tmonier_audit_log.repository.audit_log_entry'),
            service('tmonier_audit_log.resource_registry'),
            service('tmonier_audit_log.presenter'),
            service('doctrine.orm.entity_manager'),
            param('tmonier_audit_log.history_limit'),
        ])
        ->tag('twig.extension')
    ;

    $services
        ->set('tmonier_audit_log.grid_filter.resource', ResourceFilter::class)
        ->tag('sylius.grid_filter', ['type' => ResourceFilter::TYPE, 'form_type' => ResourceFilterType::class])
    ;

    $services
        ->set('tmonier_audit_log.form.type.resource_filter', ResourceFilterType::class)
        ->args([
            service('tmonier_audit_log.resource_registry'),
            service('tmonier_audit_log.presenter'),
        ])
        ->tag('form.type')
    ;

    // Retention and erasure
    $services
        ->set('tmonier_audit_log.customer_anonymizer', CustomerAuditLogAnonymizer::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            param('tmonier_audit_log.model.audit_log_entry.class'),
            param('tmonier_audit_log.personal_resources'),
            param('tmonier_audit_log.personal_fields'),
        ])
    ;

    $services
        ->set('tmonier_audit_log.command.purge', PurgeAuditLogCommand::class)
        ->args([
            service('tmonier_audit_log.repository.audit_log_entry'),
            service('clock'),
            param('tmonier_audit_log.retention_days'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('tmonier_audit_log.command.anonymize_customer', AnonymizeCustomerAuditLogCommand::class)
        ->args([
            service('tmonier_audit_log.customer_anonymizer'),
            service('sylius.repository.customer'),
        ])
        ->tag('console.command')
    ;
};
