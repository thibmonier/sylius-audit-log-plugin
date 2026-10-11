<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntry;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Twig\AuditLogPresenter;

final class AuditLogPresenterTest extends TestCase
{
    public function testFieldLabelsAreHumanizedAndKeepTheLocale(): void
    {
        $presenter = $this->presenter();

        self::assertSame('Original price', $presenter->fieldLabel('originalPrice'));
        self::assertSame('On hand', $presenter->fieldLabel('onHand'));
        self::assertSame('Name (fr_FR)', $presenter->fieldLabel('name (fr_FR)'));
        self::assertSame('Short description (en_US)', $presenter->fieldLabel('shortDescription (en_US)'));
    }

    public function testValuesAreFormattedForHumans(): void
    {
        $presenter = $this->presenter();

        self::assertSame('—', $presenter->value(null));
        self::assertSame('—', $presenter->value(''));
        self::assertSame('Yes', $presenter->value(true));
        self::assertSame('No', $presenter->value(false));
        self::assertSame('1990', $presenter->value(1990));
        self::assertSame('Web store (#1)', $presenter->value(['id' => '1', 'label' => 'Web store']));
        self::assertSame('Product #42', $presenter->value(['id' => '42', 'label' => 'Product #42']));
        self::assertSame('#3', $presenter->value(['id' => '3', 'label' => null]));
        self::assertSame('Web (#1), Mobile (#2)', $presenter->value([['id' => '1', 'label' => 'Web'], ['id' => '2', 'label' => 'Mobile']]));
    }

    public function testResourceTypesAreTranslatedWithAHumanizedFallback(): void
    {
        $presenter = $this->presenter();

        self::assertSame('Variant', $presenter->resourceTypeLabel('product_variant'));
        self::assertSame('Gift card', $presenter->resourceTypeLabel('gift_card'));
        self::assertSame('', $presenter->resourceTypeLabel(null));
    }

    public function testActors(): void
    {
        $presenter = $this->presenter();
        $entry = new AuditLogEntry();

        $entry->setActorType(AuditLogEntryInterface::ACTOR_ADMIN);
        $entry->setActorName('sylius');
        self::assertSame('sylius', $presenter->actor($entry));

        $entry->setActorType(AuditLogEntryInterface::ACTOR_CLI);
        $entry->setRoute('sylius:cancel-unpaid-orders');
        self::assertSame('Command line (sylius:cancel-unpaid-orders)', $presenter->actor($entry));

        $entry->setActorType(AuditLogEntryInterface::ACTOR_SYSTEM);
        self::assertSame('System', $presenter->actor($entry));
    }

    public function testResourceUrlsUseTheAdminShowOrUpdateRoute(): void
    {
        $presenter = $this->presenter();

        self::assertSame('/admin/products/5', $presenter->resourceUrl('product', '5'));
        self::assertSame('/admin/promotions/9/edit', $presenter->resourceUrl('promotion', '9'));
        self::assertNull($presenter->resourceUrl('channel_pricing', '3'));
        self::assertNull($presenter->resourceUrl('product_variant', '3'), 'Variant routes need the product id too');
        self::assertNull($presenter->resourceUrl('product', null));
    }

    private function presenter(): AuditLogPresenter
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'sylius.ui.yes_label' => 'Yes',
            'sylius.ui.no_label' => 'No',
            'tmonier_audit_log.resource.product_variant' => 'Variant',
            'tmonier_audit_log.ui.actor_type.cli' => 'Command line',
            'tmonier_audit_log.ui.actor_type.system' => 'System',
        ], 'en');

        $routes = new RouteCollection();
        $routes->add('sylius_admin_product_show', new Route('/admin/products/{id}'));
        $routes->add('sylius_admin_promotion_update', new Route('/admin/promotions/{id}/edit'));
        $routes->add('sylius_admin_product_variant_update', new Route('/admin/products/{productId}/variants/{id}/edit'));
        $generator = new UrlGenerator($routes, new RequestContext());

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => $generator->generate($name, $parameters));

        return new AuditLogPresenter($translator, $router);
    }
}
