<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

/** Asks the tagged resolvers in priority order; a failing resolver never breaks the audited flush. */
final class CompositeLabelResolver implements LabelResolverInterface
{
    public const MAX_LENGTH = 255;

    /** @param iterable<LabelResolverInterface> $resolvers */
    public function __construct(private readonly iterable $resolvers)
    {
    }

    public function supports(object $object): bool
    {
        return true;
    }

    public function resolve(object $object): ?string
    {
        foreach ($this->resolvers as $resolver) {
            if (!$resolver->supports($object)) {
                continue;
            }

            try {
                $label = $resolver->resolve($object);
            } catch (\Throwable) {
                $label = null;
            }

            if (null !== $label && '' !== trim($label)) {
                return mb_substr(trim($label), 0, self::MAX_LENGTH);
            }
        }

        return null;
    }
}
