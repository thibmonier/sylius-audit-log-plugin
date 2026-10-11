<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\TmonierSyliusAuditLogExtension;
use Tmonier\SyliusAuditLogPlugin\Resolver\CompositeOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\OrderAddressOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\PropertyPathOwnerResolver;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistry;

final class OwnerResolverTest extends TestCase
{
    public function testTheOwnerChainClimbsToTheAggregateRoot(): void
    {
        $registry = $this->registry();
        $resolver = new CompositeOwnerResolver([new PropertyPathOwnerResolver(PropertyAccess::createPropertyAccessor())], $registry);

        $product = new Product();
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB');
        $variant->addChannelPricing($pricing);

        $root = $resolver->resolveRoot($pricing, $this->resource($registry, $pricing), $this->entityManager());

        self::assertNotNull($root);
        self::assertSame('product', $root[0]->name);
        self::assertSame($product, $root[1]);
        self::assertNull($resolver->resolveRoot($product, $this->resource($registry, $product), $this->entityManager()));
    }

    public function testAnOrphanEntityIsItsOwnRoot(): void
    {
        $registry = $this->registry();
        $resolver = new CompositeOwnerResolver([new PropertyPathOwnerResolver(PropertyAccess::createPropertyAccessor())], $registry);
        $pricing = new ChannelPricing();

        self::assertNull($resolver->resolveRoot($pricing, $this->resource($registry, $pricing), $this->entityManager()));
    }

    public function testCustomerAddressesBelongToTheCustomerAndOrderAddressesToTheOrder(): void
    {
        $registry = $this->registry();
        $customer = new Customer();
        $bookAddress = new Address();
        $customer->addAddress($bookAddress);
        $orderAddress = new Address();
        $order = new Order();
        $order->setBillingAddress($orderAddress);

        $resolver = new CompositeOwnerResolver([
            new OrderAddressOwnerResolver(),
            new PropertyPathOwnerResolver(PropertyAccess::createPropertyAccessor()),
        ], $registry);
        $entityManager = $this->entityManager([Order::class => [42 => $order]]);

        self::assertSame($customer, $resolver->resolveRoot($bookAddress, $this->resource($registry, $bookAddress), $entityManager)[1] ?? null);
        self::assertSame($order, $resolver->resolveRoot($orderAddress, $this->resource($registry, $orderAddress), $entityManager)[1] ?? null);
    }

    private function registry(): AuditedResourceRegistry
    {
        return new AuditedResourceRegistry(TmonierSyliusAuditLogExtension::mergeResources([]));
    }

    private function resource(AuditedResourceRegistry $registry, object $entity): AuditedResource
    {
        $resource = $registry->match($entity);
        self::assertNotNull($resource);

        return $resource;
    }

    /** @param array<class-string, array<int, object>> $identityMap */
    private function entityManager(array $identityMap = []): EntityManagerInterface
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getIdentityMap')->willReturn($identityMap);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $entityManager;
    }
}
