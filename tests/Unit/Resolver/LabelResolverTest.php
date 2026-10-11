<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\TaxRate;
use Tmonier\SyliusAuditLogPlugin\Resolver\CompositeLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\CoreLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\GenericLabelResolver;
use Tmonier\SyliusAuditLogPlugin\Resolver\LabelResolverInterface;

final class LabelResolverTest extends TestCase
{
    public function testGenericLabelsUseNameAndCode(): void
    {
        $resolver = new GenericLabelResolver();

        $channel = new Channel();
        $channel->setCode('WEB');
        self::assertSame('WEB', $resolver->resolve($channel));
        $channel->setName('Web store');
        self::assertSame('Web store (WEB)', $resolver->resolve($channel));

        $taxRate = new TaxRate();
        $taxRate->setName('VAT');
        self::assertSame('VAT', $resolver->resolve($taxRate));

        self::assertNull($resolver->resolve(new \stdClass()));
    }

    public function testGenericLabelsSurviveGettersThatThrow(): void
    {
        // An untranslated product throws when its name is read.
        $product = new Product();
        $product->setCode('HAT');

        self::assertSame('HAT', (new GenericLabelResolver())->resolve($product));
    }

    public function testCoreResourcesHaveMeaningfulLabels(): void
    {
        $resolver = new CoreLabelResolver();

        $order = new Order();
        self::assertTrue($resolver->supports($order));
        self::assertNull($resolver->resolve($order));
        $order->setNumber('000042');
        self::assertSame('#000042', $resolver->resolve($order));

        $customer = new Customer();
        $customer->setEmail('jane@example.com');
        self::assertSame('jane@example.com', $resolver->resolve($customer));

        $admin = new AdminUser();
        $admin->setUsername('sylius');
        self::assertSame('sylius', $resolver->resolve($admin));

        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Wool hat');
        $variant = new ProductVariant();
        $variant->setCode('HAT_S');
        self::assertSame('HAT_S', $resolver->resolve($variant), 'Untranslated variant');
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $product->addVariant($variant);
        self::assertSame('Wool hat (HAT_S)', $resolver->resolve($variant));

        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB');
        $variant->addChannelPricing($pricing);
        self::assertSame('HAT_S @ WEB', $resolver->resolve($pricing));

        $address = new Address();
        $address->setFirstName('Jane');
        $address->setLastName('Doe');
        $address->setCity('Lyon');
        $address->setCountryCode('FR');
        self::assertSame('Jane Doe, Lyon, FR', $resolver->resolve($address));

        $method = new PaymentMethod();
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Bank transfer');
        $payment = new Payment();
        $payment->setMethod($method);
        self::assertSame('Bank transfer', $resolver->resolve($payment));

        self::assertFalse($resolver->supports(new Channel()));
    }

    public function testTheCompositeResolverAsksResolversInOrderAndSwallowsErrors(): void
    {
        $failing = new class() implements LabelResolverInterface {
            public function supports(object $object): bool
            {
                return true;
            }

            public function resolve(object $object): ?string
            {
                throw new \RuntimeException('Broken');
            }
        };
        $long = new class() implements LabelResolverInterface {
            public function supports(object $object): bool
            {
                return true;
            }

            public function resolve(object $object): string
            {
                return '  ' . str_repeat('x', 300);
            }
        };

        $resolver = new CompositeLabelResolver([$failing, new CoreLabelResolver(), $long]);

        $customer = new Customer();
        $customer->setEmail('jane@example.com');
        self::assertSame('jane@example.com', $resolver->resolve($customer));
        self::assertSame(CompositeLabelResolver::MAX_LENGTH, mb_strlen((string) $resolver->resolve(new \stdClass())));
        self::assertNull((new CompositeLabelResolver([$failing]))->resolve(new \stdClass()));
    }
}
