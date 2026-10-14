<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/** Labels of the Sylius core resources the generic resolver cannot name well. */
final class CoreLabelResolver implements LabelResolverInterface
{
    public function supports(object $object): bool
    {
        return $object instanceof OrderInterface ||
            $object instanceof CustomerInterface ||
            $object instanceof AdminUserInterface ||
            $object instanceof ProductVariantInterface ||
            $object instanceof ChannelPricingInterface ||
            $object instanceof AddressInterface ||
            $object instanceof PaymentInterface ||
            $object instanceof ShipmentInterface;
    }

    public function resolve(object $object): ?string
    {
        return match (true) {
            $object instanceof OrderInterface => null !== $object->getNumber() ? '#' . $object->getNumber() : null,
            $object instanceof CustomerInterface => $object->getEmail(),
            $object instanceof AdminUserInterface => $object->getUsername(),
            $object instanceof ProductVariantInterface => $this->variant($object),
            $object instanceof ChannelPricingInterface => trim(sprintf('%s @ %s', (string) $object->getProductVariant()?->getCode(), (string) $object->getChannelCode())),
            $object instanceof AddressInterface => $this->address($object),
            $object instanceof PaymentInterface => $object->getMethod()?->getName(),
            $object instanceof ShipmentInterface => $object->getMethod()?->getName(),
            default => null,
        };
    }

    private function variant(ProductVariantInterface $variant): ?string
    {
        $product = $this->safe(static fn (): ?string => $variant->getProduct()?->getName());
        $name = $this->safe(static fn (): ?string => $variant->getName());
        $code = $variant->getCode();

        $label = implode(' - ', array_filter([$product, $name !== $product ? $name : null], static fn (?string $part): bool => null !== $part && '' !== $part));
        if (null === $code) {
            return '' === $label ? null : $label;
        }

        return '' === $label ? $code : sprintf('%s (%s)', $label, $code);
    }

    /** @param \Closure(): ?string $getter translated getters throw when no locale is set */
    private function safe(\Closure $getter): ?string
    {
        try {
            return $getter();
        } catch (\Throwable) {
            return null;
        }
    }

    private function address(AddressInterface $address): string
    {
        return implode(', ', array_filter([
            $address->getFullName(),
            $address->getCity(),
            $address->getCountryCode(),
        ], static fn (?string $part): bool => null !== $part && '' !== trim($part)));
    }
}
