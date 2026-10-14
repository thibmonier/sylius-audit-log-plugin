<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Diff;

use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

/** Decides which fields are skipped and which ones are recorded without their values. */
final class FieldPolicy
{
    /** @var array<string, true> */
    private array $ignored;

    /** @var array<string, true> */
    private array $masked;

    /**
     * @param list<string> $ignoredFields
     * @param list<string> $maskedFields
     * @param list<string> $maskedFieldPatterns regular expressions
     */
    public function __construct(
        array $ignoredFields,
        array $maskedFields,
        private readonly array $maskedFieldPatterns = [],
    ) {
        $this->ignored = array_fill_keys($ignoredFields, true);
        $this->masked = array_fill_keys(array_map(strtolower(...), $maskedFields), true);
    }

    public function isIgnored(string $field, ?AuditedResource $resource = null): bool
    {
        return isset($this->ignored[$field]) || ($resource instanceof \Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource && in_array($field, $resource->ignoredFields, true));
    }

    public function isMasked(string $field): bool
    {
        if (isset($this->masked[strtolower($field)])) {
            return true;
        }

        foreach ($this->maskedFieldPatterns as $pattern) {
            if (1 === @preg_match($pattern, $field)) {
                return true;
            }
        }

        return false;
    }
}
