<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resolver;

/** Fallback: "name (code)", the name, the code, or another identifying getter. */
final class GenericLabelResolver implements LabelResolverInterface
{
    private const GETTERS = ['getNumber', 'getUsername', 'getEmail', 'getType', 'getTitle'];

    public function supports(object $object): bool
    {
        return true;
    }

    public function resolve(object $object): ?string
    {
        $name = $this->call($object, 'getName');
        $code = $this->call($object, 'getCode');

        if (null !== $name && null !== $code && $name !== $code) {
            return sprintf('%s (%s)', $name, $code);
        }
        if (null !== ($name ?? $code)) {
            return $name ?? $code;
        }

        foreach (self::GETTERS as $getter) {
            $value = $this->call($object, $getter);
            if (null !== $value) {
                return $value;
            }
        }

        return $object instanceof \Stringable ? (string) $object : null;
    }

    private function call(object $object, string $method): ?string
    {
        if (!method_exists($object, $method)) {
            return null;
        }

        try {
            /** @var mixed $value */
            $value = $object->{$method}();
        } catch (\Throwable) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return is_scalar($value) && '' !== trim((string) $value) ? (string) $value : null;
    }
}
