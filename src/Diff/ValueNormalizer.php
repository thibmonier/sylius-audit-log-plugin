<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Diff;

/**
 * Turns a field value into something JSON can store: scalars stay scalars, text is truncated,
 * dates become ISO 8601 strings, arrays are JSON-encoded, entities become references.
 */
final class ValueNormalizer
{
    public const ELLIPSIS = '…';

    public function __construct(private readonly int $maxLength = 500)
    {
    }

    /** @param \Closure(object): ?ObjectReference $referenceFactory turns entities into references, null for non-entities */
    public function normalize(mixed $value, \Closure $referenceFactory): bool|float|int|string|ObjectReference|null
    {
        return match (true) {
            null === $value, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => $this->truncate($value),
            $value instanceof \BackedEnum => is_string($value->value) ? $this->truncate($value->value) : $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            is_array($value) => $this->truncate($this->encode($value)),
            is_object($value) => $referenceFactory($value) ?? $this->describe($value),
            default => $this->truncate(get_debug_type($value)),
        };
    }

    public function truncate(string $value): string
    {
        if (mb_strlen($value) <= $this->maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $this->maxLength - 1) . self::ELLIPSIS;
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return false === $json ? '[]' : $json;
    }

    private function describe(object $value): string
    {
        return $this->truncate($value instanceof \Stringable ? (string) $value : get_debug_type($value));
    }
}
