<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\AdminBundle\SectionResolver\AdminSection;
use Sylius\Bundle\ApiBundle\SectionResolver\AdminApiSection;
use Sylius\Bundle\ApiBundle\SectionResolver\ShopApiSection;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionInterface;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Tmonier\SyliusAuditLogPlugin\Context\AuditContextProvider;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

final class AuditContextProviderTest extends TestCase
{
    /** @return iterable<string, array{?SectionInterface, string}> */
    public static function sections(): iterable
    {
        yield 'admin' => [new AdminSection(), AuditLogEntryInterface::SOURCE_ADMIN];
        yield 'admin API' => [new AdminApiSection(), AuditLogEntryInterface::SOURCE_ADMIN_API];
        yield 'shop' => [new ShopSection(), AuditLogEntryInterface::SOURCE_SHOP];
        yield 'shop API' => [new ShopApiSection(), AuditLogEntryInterface::SOURCE_SHOP_API];
        yield 'unknown' => [null, AuditLogEntryInterface::SOURCE_OTHER];
    }

    #[DataProvider('sections')]
    public function testTheSourceOfARequestComesFromTheSyliusSection(?SectionInterface $section, string $source): void
    {
        $provider = $this->provider($this->requestStack(new Request()), $section);

        self::assertSame($source, $provider->getSource());
    }

    public function testCodeRunningWithoutRequestComesFromTheCommandLine(): void
    {
        $provider = $this->provider(new RequestStack());
        $provider->onConsoleCommand(new ConsoleCommandEvent(new Command('sylius:cancel-unpaid-orders'), new ArrayInput([]), new NullOutput()));

        $context = $provider->getContext();

        self::assertSame(AuditLogEntryInterface::SOURCE_CLI, $provider->getSource());
        self::assertSame('sylius:cancel-unpaid-orders', $provider->getCommandName());
        self::assertSame(AuditLogEntryInterface::ACTOR_CLI, $context->actorType);
        self::assertNull($context->actorId);
        self::assertSame('sylius:cancel-unpaid-orders', $context->route);
        self::assertSame($context->requestId, $provider->getContext()->requestId, 'One correlation id per command');

        $provider->onConsoleTerminate();
        self::assertNull($provider->getCommandName());
        self::assertNotSame($context->requestId, $provider->getContext()->requestId);
    }

    public function testAnAdministratorIsTheActorOfAnAdminRequest(): void
    {
        $admin = $this->createMock(AdminUserInterface::class);
        $admin->method('getId')->willReturn(7);
        $admin->method('getUsername')->willReturn('sylius');
        $admin->method('getRoles')->willReturn(['ROLE_ADMINISTRATION_ACCESS']);
        $request = new Request();
        $request->attributes->set('_route', 'sylius_admin_product_update');

        $context = $this->provider($this->requestStack($request), new AdminSection(), $admin)->getContext();

        self::assertSame(AuditLogEntryInterface::ACTOR_ADMIN, $context->actorType);
        self::assertSame(7, $context->actorId);
        self::assertSame('sylius', $context->actorName);
        self::assertSame(AuditLogEntryInterface::SOURCE_ADMIN, $context->source);
        self::assertSame('sylius_admin_product_update', $context->route);
        self::assertNull($context->ipAddress, 'IP addresses are not stored by default');
    }

    public function testAShopUserIsRecordedAsItsCustomer(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(12);
        $customer->method('getEmail')->willReturn('jane@example.com');
        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);
        $shopUser->method('getRoles')->willReturn(['ROLE_USER']);

        $context = $this->provider($this->requestStack(new Request()), new ShopSection(), $shopUser)->getContext();

        self::assertSame(AuditLogEntryInterface::ACTOR_CUSTOMER, $context->actorType);
        self::assertSame(12, $context->actorId);
        self::assertSame('jane@example.com', $context->actorName);
    }

    public function testAnAnonymousRequestIsMadeByTheSystem(): void
    {
        $context = $this->provider($this->requestStack(new Request()), new ShopSection())->getContext();

        self::assertSame(AuditLogEntryInterface::ACTOR_SYSTEM, $context->actorType);
        self::assertNull($context->actorName);
    }

    public function testTheIpAddressIsStoredOnlyWhenEnabledAndAnonymizedByDefault(): void
    {
        $request = new Request(server: ['REMOTE_ADDR' => '203.0.113.42']);

        self::assertSame('203.0.113.0', $this->provider($this->requestStack($request), storeIp: true)->getContext()->ipAddress);
        self::assertSame('203.0.113.42', $this->provider($this->requestStack($request), storeIp: true, anonymizeIp: false)->getContext()->ipAddress);
    }

    public function testTheRequestIdIsSharedByTheRequestAndCanComeFromAHeader(): void
    {
        $request = new Request();
        $provider = $this->provider($this->requestStack($request));
        $id = $provider->getContext()->requestId;

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertSame($id, $provider->getContext()->requestId);

        $traced = new Request();
        $traced->headers->set('X-Request-Id', 'trace-1234-abcd');
        self::assertSame('trace-1234-abcd', $this->provider($this->requestStack($traced))->getContext()->requestId);

        $invalid = new Request();
        $invalid->headers->set('X-Request-Id', '<script>');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->provider($this->requestStack($invalid))->getContext()->requestId);
    }

    private function provider(
        RequestStack $requestStack,
        ?SectionInterface $section = null,
        ?UserInterface $user = null,
        bool $storeIp = false,
        bool $anonymizeIp = true,
    ): AuditContextProvider {
        $tokenStorage = new TokenStorage();
        if ($user instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $sectionProvider = $this->createMock(SectionProviderInterface::class);
        $sectionProvider->method('getSection')->willReturn($section);

        return new AuditContextProvider($requestStack, $tokenStorage, $sectionProvider, $storeIp, $anonymizeIp);
    }

    private function requestStack(Request $request): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
