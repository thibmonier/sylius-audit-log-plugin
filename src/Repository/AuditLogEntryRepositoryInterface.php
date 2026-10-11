<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/**
 * @extends RepositoryInterface<AuditLogEntryInterface>
 */
interface AuditLogEntryRepositoryInterface extends RepositoryInterface
{
    /**
     * Entries of a resource and of the resources it owns (variants of a product...), newest first.
     *
     * @return list<AuditLogEntryInterface>
     */
    public function findForOwner(string $ownerType, string $ownerId, int $limit): array;

    public function countForOwner(string $ownerType, string $ownerId): int;

    public function countOlderThan(\DateTimeImmutable $limit): int;

    /** Deletes entries created before the limit, in batches; returns the number of deleted entries. */
    public function deleteOlderThan(\DateTimeImmutable $limit, int $batchSize = 1000): int;
}
