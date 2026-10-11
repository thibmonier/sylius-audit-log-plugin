<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Maintenance;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/**
 * Right to erasure: removes the personal data of a customer from the audit trail while keeping
 * who did what and when. Covered entries: the customer, its addresses, its orders (and their
 * payments, shipments, addresses) and the changes the customer made from the shop.
 *
 * - entries about a personal resource (customer, address): every value and the label;
 * - other entries (orders...): the values of the personal fields;
 * - all of them: the IP address, and the actor name when the customer is the actor.
 */
final class CustomerAuditLogAnonymizer
{
    public const PLACEHOLDER = '[anonymized]';

    /**
     * @param class-string<AuditLogEntryInterface> $entryClass
     * @param list<string> $personalResources
     * @param list<string> $personalFields
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $entryClass,
        private readonly array $personalResources,
        private readonly array $personalFields,
    ) {
    }

    public function count(int $customerId, ?string $email = null): int
    {
        return (int) $this->matching($customerId, $email)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return int number of anonymized entries */
    public function anonymize(int $customerId, ?string $email = null, int $batchSize = 200): int
    {
        $count = 0;
        $lastId = 0;
        do {
            /** @var list<AuditLogEntryInterface> $entries */
            $entries = $this->matching($customerId, $email)
                ->andWhere('o.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('o.id', 'ASC')
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult()
            ;

            foreach ($entries as $entry) {
                $this->anonymizeEntry($entry, $customerId, $email);
                $lastId = (int) $entry->getId();
                ++$count;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } while (count($entries) === $batchSize);

        return $count;
    }

    public function anonymizeEntry(AuditLogEntryInterface $entry, int $customerId, ?string $email = null): void
    {
        $personalResource = in_array($entry->getResourceType(), $this->personalResources, true);

        $changes = [];
        foreach ($entry->getChanges() as $field => $change) {
            $personalField = $personalResource || $this->isPersonalField($field);
            foreach ($change as $key => $value) {
                $change[$key] = match (true) {
                    'masked' === $key => $value,
                    $personalField => $this->erase($value),
                    default => $this->eraseEmail($value, $email),
                };
            }
            $changes[$field] = $change;
        }
        $entry->setChanges($changes);

        if ($personalResource || (null !== $email && $entry->getResourceLabel() === $email)) {
            $entry->setResourceLabel(self::PLACEHOLDER);
        }
        if (AuditLogEntryInterface::ACTOR_CUSTOMER === $entry->getActorType() && $customerId === $entry->getActorId()) {
            $entry->setActorName(self::PLACEHOLDER);
        }
        $entry->setIpAddress(null);
    }

    private function matching(int $customerId, ?string $email): QueryBuilder
    {
        /** @var list<int> $orderIds */
        $orderIds = $this->entityManager->createQueryBuilder()
            ->select('ord.id')
            ->from(OrderInterface::class, 'ord')
            ->andWhere('IDENTITY(ord.customer) = :customer')
            ->setParameter('customer', $customerId)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        $queryBuilder = $this->entityManager->createQueryBuilder()->select('o')->from($this->entryClass, 'o');
        $expr = $queryBuilder->expr();
        $conditions = $expr->orX(
            $expr->andX('o.ownerType = :customerType', 'o.ownerId = :customerId'),
            $expr->andX('o.resourceType = :customerType', 'o.resourceId = :customerId'),
            $expr->andX('o.actorType = :customerActor', 'o.actorId = :customerIdInt'),
        );
        $queryBuilder
            ->setParameter('customerType', 'customer')
            ->setParameter('customerId', (string) $customerId)
            ->setParameter('customerActor', AuditLogEntryInterface::ACTOR_CUSTOMER)
            ->setParameter('customerIdInt', $customerId)
        ;

        if ([] !== $orderIds) {
            $conditions->add($expr->andX('o.ownerType = :orderType', 'o.ownerId IN (:orderIds)'));
            $queryBuilder
                ->setParameter('orderType', 'order')
                ->setParameter('orderIds', array_map('strval', $orderIds))
            ;
        }
        if (null !== $email && '' !== $email) {
            $conditions->add($expr->andX('o.resourceType = :customerType', 'o.resourceLabel = :email'));
            $queryBuilder->setParameter('email', $email);
        }

        return $queryBuilder->andWhere($conditions);
    }

    private function isPersonalField(string $field): bool
    {
        // Translated fields are stored as "name (en_US)".
        $field = (string) preg_replace('/ \(.*\)$/', '', $field);

        return in_array($field, $this->personalFields, true);
    }

    private function erase(mixed $value): mixed
    {
        return match (true) {
            null === $value => null,
            is_array($value) && array_is_list($value) => array_map(fn (mixed $item): mixed => $this->erase($item), $value),
            is_array($value) => ['id' => $value['id'] ?? null, 'label' => self::PLACEHOLDER],
            default => self::PLACEHOLDER,
        };
    }

    private function eraseEmail(mixed $value, ?string $email): mixed
    {
        if (null === $email || '' === $email) {
            return $value;
        }

        return match (true) {
            is_string($value) => str_ireplace($email, self::PLACEHOLDER, $value),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->eraseEmail($item, $email), $value),
            default => $value,
        };
    }
}
