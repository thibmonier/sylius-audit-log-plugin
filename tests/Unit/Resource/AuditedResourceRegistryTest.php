<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Resource;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\Taxon;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistry;

final class AuditedResourceRegistryTest extends TestCase
{
    public function testEntitiesMatchTheFirstConfiguredClassOrInterface(): void
    {
        $registry = new AuditedResourceRegistry([
            'product' => ['class' => ProductInterface::class, 'ignored_fields' => ['position']],
            'channel' => ['class' => ChannelInterface::class],
            'any_channel' => ['class' => Channel::class],
        ]);

        self::assertSame('product', $registry->match(new Product())?->name);
        self::assertSame(['position'], $registry->match(new Product())?->ignoredFields);
        self::assertSame('channel', $registry->match(new Channel())?->name);
        self::assertNull($registry->match(new Taxon()));
        self::assertNull($registry->match(new Taxon()), 'Cached negative match');
        self::assertSame(ProductInterface::class, $registry->get('product')?->class);
        self::assertNull($registry->get('unknown'));
        self::assertSame(['product', 'channel', 'any_channel'], array_keys($registry->all()));
    }
}
