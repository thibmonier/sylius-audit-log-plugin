<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

class AuditLogEntryRepository extends EntityRepository implements AuditLogEntryRepositoryInterface
{
    public function findForOwner(string $ownerType, string $ownerId, int $limit): array
    {
        /** @var list<AuditLogEntryInterface> $entries */
        $entries = $this->createQueryBuilder('o')
            ->andWhere('o.ownerType = :type')
            ->andWhere('o.ownerId = :id')
            ->setParameter('type', $ownerType)
            ->setParameter('id', $ownerId)
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $entries;
    }

    public function countForOwner(string $ownerType, string $ownerId): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.ownerType = :type')
            ->andWhere('o.ownerId = :id')
            ->setParameter('type', $ownerType)
            ->setParameter('id', $ownerId)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function countOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.createdAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function deleteOlderThan(\DateTimeImmutable $limit, int $batchSize = 1000): int
    {
        $deleted = 0;
        do {
            /** @var list<int> $ids */
            $ids = $this->createQueryBuilder('o')
                ->select('o.id')
                ->andWhere('o.createdAt < :limit')
                ->setParameter('limit', $limit)
                ->orderBy('o.id', 'ASC')
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getSingleColumnResult()
            ;

            if ([] === $ids) {
                break;
            }

            $result = $this->createQueryBuilder('o')
                ->delete()
                ->andWhere('o.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute()
            ;
            $deleted += is_int($result) ? $result : count($ids);
        } while (count($ids) === $batchSize);

        return $deleted;
    }
}
