<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

/**
 * Order addresses have no link back to their order: look for a loaded order using the address
 * (the admin form that edits it has loaded the order). Address book entries keep the customer.
 */
final class OrderAddressOwnerResolver implements OwnerResolverInterface
{
    public function supports(object $entity, AuditedResource $resource): bool
    {
        return $entity instanceof AddressInterface && null === $entity->getCustomer();
    }

    public function resolve(object $entity, AuditedResource $resource, EntityManagerInterface $entityManager): ?object
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $candidates = [...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()];
        foreach ($unitOfWork->getIdentityMap() as $entities) {
            foreach ($entities as $candidate) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof OrderInterface && ($candidate->getBillingAddress() === $entity || $candidate->getShippingAddress() === $entity)) {
                return $candidate;
            }
        }

        return null;
    }
}
