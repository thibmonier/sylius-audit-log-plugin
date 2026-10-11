<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\PromotionCouponInterface;
use Sylius\Component\Core\Model\PromotionInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Development helper (test application only): makes realistic admin changes through Doctrine
 * so that the audit log has something to show. Each change runs inside a fake admin request.
 */
#[AsCommand(name: 'tmonier:audit-log:demo', description: 'Generate demo audit log entries (development only)')]
final class GenerateDemoAuditLogCommand extends Command
{
    /**
     * @param FactoryInterface<PromotionCouponInterface> $couponFactory
     * @param FactoryInterface<TaxonInterface> $taxonFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly FactoryInterface $couponFactory,
        private readonly FactoryInterface $taxonFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $admin = $this->entityManager->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        if (!$admin instanceof AdminUserInterface) {
            $io->error('Load the Sylius fixtures first.');

            return Command::FAILURE;
        }
        $this->tokenStorage->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));

        /** @var list<ProductInterface> $products */
        $products = $this->entityManager->getRepository(ProductInterface::class)->findBy([], ['id' => 'ASC'], 3);
        foreach ($products as $index => $product) {
            $product->setCurrentLocale('en_US');
            $product->setFallbackLocale('en_US');
            $this->asAdmin('sylius_admin_product_update', sprintf('/admin/products/%d/edit', (int) $product->getId()), function () use ($product, $index): void {
                $product->setName($product->getName() . ' (new collection)');
                $product->setShortDescription('Updated for the autumn catalogue.');
                if (0 === $index) {
                    $channel = $product->getChannels()->first();
                    if (false !== $channel) {
                        $product->removeChannel($channel);
                    }
                }
                $variant = $product->getVariants()->first();
                if (false !== $variant) {
                    $variant->setOnHand((int) $variant->getOnHand() + 25);
                    $pricing = $variant->getChannelPricings()->first();
                    if (false !== $pricing) {
                        $pricing->setOriginalPrice($pricing->getPrice());
                        $pricing->setPrice((int) round((int) $pricing->getPrice() * 0.8));
                    }
                }
            });
        }

        $promotion = $this->entityManager->getRepository(PromotionInterface::class)->findOneBy([]);
        if ($promotion instanceof PromotionInterface) {
            $this->asAdmin('sylius_admin_promotion_update', sprintf('/admin/promotions/%d/edit', (int) $promotion->getId()), static function () use ($promotion): void {
                $promotion->setPriority($promotion->getPriority() + 1);
                $promotion->setUsageLimit(500);
            });
            $this->asAdmin('sylius_admin_promotion_coupon_create', sprintf('/admin/promotions/%d/coupons/new', (int) $promotion->getId()), function () use ($promotion): void {
                $coupon = $this->couponFactory->createNew();
                $coupon->setCode('AUTUMN-' . strtoupper(bin2hex(random_bytes(3))));
                $coupon->setUsageLimit(1);
                $promotion->setCouponBased(true);
                $promotion->addCoupon($coupon);
                $this->entityManager->persist($coupon);
            });
        }

        /** @var OrderInterface|null $order */
        $order = $this->entityManager->getRepository(OrderInterface::class)->findOneBy(['checkoutState' => 'completed'], ['id' => 'DESC']);
        if (null !== $order) {
            $this->asAdmin('sylius_admin_order_update', sprintf('/admin/orders/%d/edit', (int) $order->getId()), static function () use ($order): void {
                $order->setNotes('Customer asked for delivery after 6 pm.');
                $order->getShippingAddress()?->setStreet('12 Rue de la Paix');
            });

            $customer = $order->getCustomer();
            if ($customer instanceof CustomerInterface) {
                $this->asAdmin('sylius_admin_customer_update', sprintf('/admin/customers/%d/edit', (int) $customer->getId()), static function () use ($customer): void {
                    $customer->setPhoneNumber('+33 1 23 45 67 89');
                    $customer->setSubscribedToNewsletter(!$customer->isSubscribedToNewsletter());
                });
            }
        }

        $taxRate = $this->entityManager->getRepository(TaxRateInterface::class)->findOneBy([]);
        if ($taxRate instanceof TaxRateInterface) {
            $this->asAdmin('sylius_admin_tax_rate_update', sprintf('/admin/tax-rates/%d/edit', (int) $taxRate->getId()), static function () use ($taxRate): void {
                $taxRate->setAmount(round((float) $taxRate->getAmount() + 0.01, 4));
            });
        }

        $shippingMethod = $this->entityManager->getRepository(ShippingMethodInterface::class)->findOneBy([]);
        if ($shippingMethod instanceof ShippingMethodInterface) {
            $shippingMethod->setCurrentLocale('en_US');
            $shippingMethod->setFallbackLocale('en_US');
            $this->asAdmin('sylius_admin_shipping_method_update', sprintf('/admin/shipping-methods/%d/edit', (int) $shippingMethod->getId()), static function () use ($shippingMethod): void {
                $shippingMethod->setName($shippingMethod->getName() . ' Express');
            });
        }

        $taxon = $this->taxonFactory->createNew();
        $taxon->setCode('DEMO_AUDIT_' . strtoupper(bin2hex(random_bytes(2))));
        $taxon->setCurrentLocale('en_US');
        $taxon->setFallbackLocale('en_US');
        $taxon->setName('Seasonal picks');
        $taxon->setSlug('seasonal-picks-' . bin2hex(random_bytes(2)));
        $this->asAdmin('sylius_admin_taxon_create', '/admin/taxons/new', function () use ($taxon): void {
            $this->entityManager->persist($taxon);
        });
        $this->asAdmin('sylius_admin_taxon_delete', sprintf('/admin/taxons/%d', (int) $taxon->getId()), function () use ($taxon): void {
            $this->entityManager->remove($taxon);
        });

        // Spread the requests over the last days for the date filter (MySQL syntax, dev only).
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE tmonier_audit_log_entry SET created_at = created_at - INTERVAL (CRC32(request_id) % 9) DAY - INTERVAL (CRC32(request_id) % 7) HOUR WHERE route LIKE ?',
            ['sylius_admin_%'],
        );

        $io->success('Demo audit log entries generated: open /admin/audit-log.');

        return Command::SUCCESS;
    }

    private function asAdmin(string $route, string $path, callable $change): void
    {
        $request = Request::create($path, 'PUT', server: ['REMOTE_ADDR' => '203.0.113.' . random_int(2, 250)]);
        $request->attributes->set('_route', $route);
        $this->requestStack->push($request);

        try {
            $change();
            $this->entityManager->flush();
        } finally {
            $this->requestStack->pop();
        }
    }
}
