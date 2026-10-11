<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Twig;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;
use Tmonier\SyliusAuditLogPlugin\Repository\AuditLogEntryRepositoryInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AuditLogExtension extends AbstractExtension
{
    public function __construct(
        private readonly AuditLogEntryRepositoryInterface $repository,
        private readonly AuditedResourceRegistryInterface $registry,
        private readonly AuditLogPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $historyLimit,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_audit_log_history', $this->history(...)),
            new TwigFunction('tmonier_audit_log_resource_type', $this->presenter->resourceTypeLabel(...)),
            new TwigFunction('tmonier_audit_log_field', $this->presenter->fieldLabel(...)),
            new TwigFunction('tmonier_audit_log_value', $this->presenter->value(...)),
            new TwigFunction('tmonier_audit_log_actor', $this->presenter->actor(...)),
            new TwigFunction('tmonier_audit_log_resource_url', $this->presenter->resourceUrl(...)),
        ];
    }

    /**
     * History of an audited resource and of the resources it owns, for the admin cards.
     *
     * @return array{type: string, id: string, entries: list<AuditLogEntryInterface>, total: int}|null
     */
    public function history(mixed $resource, ?int $limit = null): ?array
    {
        if (!is_object($resource)) {
            return null;
        }

        $audited = $this->registry->match($resource);
        if (null === $audited) {
            return null;
        }

        try {
            $identifier = $this->entityManager->getClassMetadata($resource::class)->getIdentifierValues($resource);
        } catch (\Throwable) {
            return null;
        }

        $id = reset($identifier);
        if (!is_scalar($id)) {
            return null;
        }

        $id = (string) $id;

        return [
            'type' => $audited->name,
            'id' => $id,
            'entries' => $this->repository->findForOwner($audited->name, $id, $limit ?? $this->historyLimit),
            'total' => $this->repository->countForOwner($audited->name, $id),
        ];
    }
}
