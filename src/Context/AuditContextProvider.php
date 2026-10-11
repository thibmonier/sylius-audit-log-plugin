<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Context;

use Sylius\Bundle\AdminBundle\SectionResolver\AdminSection;
use Sylius\Bundle\ApiBundle\SectionResolver\AdminApiSection;
use Sylius\Bundle\ApiBundle\SectionResolver\ShopApiSection;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

final class AuditContextProvider implements AuditContextProviderInterface, ResetInterface
{
    public const REQUEST_ID_ATTRIBUTE = '_tmonier_audit_log_request_id';

    public const REQUEST_ID_HEADER = 'X-Request-Id';

    private ?string $commandName = null;

    /** Correlation id of the code running outside of an HTTP request (console command, worker). */
    private ?string $requestId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ?SectionProviderInterface $sectionProvider,
        private readonly bool $storeIp = false,
        private readonly bool $anonymizeIp = true,
    ) {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->commandName = $event->getCommand()?->getName();
        $this->requestId = null;
    }

    public function onConsoleTerminate(): void
    {
        $this->commandName = null;
        $this->requestId = null;
    }

    public function getCommandName(): ?string
    {
        return null === $this->requestStack->getMainRequest() ? $this->commandName : null;
    }

    public function getSource(): string
    {
        if (null === $this->requestStack->getMainRequest()) {
            return \PHP_SAPI === 'cli' ? AuditLogEntryInterface::SOURCE_CLI : AuditLogEntryInterface::SOURCE_OTHER;
        }

        $section = $this->sectionProvider?->getSection();

        return match (true) {
            $section instanceof AdminApiSection => AuditLogEntryInterface::SOURCE_ADMIN_API,
            $section instanceof ShopApiSection => AuditLogEntryInterface::SOURCE_SHOP_API,
            $section instanceof AdminSection => AuditLogEntryInterface::SOURCE_ADMIN,
            $section instanceof ShopSection => AuditLogEntryInterface::SOURCE_SHOP,
            default => AuditLogEntryInterface::SOURCE_OTHER,
        };
    }

    public function getContext(): AuditContext
    {
        $request = $this->requestStack->getMainRequest();
        $source = $this->getSource();
        [$actorType, $actorId, $actorName] = $this->resolveActor($source);

        return new AuditContext(
            $actorType,
            $actorId,
            $actorName,
            $source,
            $this->resolveIp($request),
            $this->resolveRequestId($request),
            null !== $request ? $this->routeOf($request) : $this->getCommandName(),
        );
    }

    public function reset(): void
    {
        $this->requestId = null;
    }

    /** @return array{string, ?int, ?string} */
    private function resolveActor(string $source): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if ($user instanceof AdminUserInterface) {
            return [AuditLogEntryInterface::ACTOR_ADMIN, $this->intId($user->getId()), $user->getUsername()];
        }

        if ($user instanceof ShopUserInterface) {
            $customer = $user->getCustomer();

            return [AuditLogEntryInterface::ACTOR_CUSTOMER, $this->intId($customer?->getId()), $customer?->getEmail() ?? $user->getUsername()];
        }

        if (AuditLogEntryInterface::SOURCE_CLI === $source) {
            return [AuditLogEntryInterface::ACTOR_CLI, null, null];
        }

        return [AuditLogEntryInterface::ACTOR_SYSTEM, null, null];
    }

    private function resolveIp(?Request $request): ?string
    {
        if (!$this->storeIp || null === $request) {
            return null;
        }

        $ip = $request->getClientIp();
        if (null === $ip) {
            return null;
        }

        return $this->anonymizeIp ? IpUtils::anonymize($ip) : $ip;
    }

    private function resolveRequestId(?Request $request): string
    {
        if (null === $request) {
            return $this->requestId ??= self::generateId();
        }

        $id = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (!is_string($id)) {
            $header = $request->headers->get(self::REQUEST_ID_HEADER);
            $id = null !== $header && 1 === preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $header) ? $header : self::generateId();
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $id);
        }

        return $id;
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) ? mb_substr($route, 0, 255) : null;
    }

    private function intId(mixed $id): ?int
    {
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
