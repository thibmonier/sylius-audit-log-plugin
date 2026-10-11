<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusAuditLogPlugin\Diff\FieldPolicy;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResource;

final class FieldPolicyTest extends TestCase
{
    public function testGlobalAndPerResourceIgnoredFields(): void
    {
        $policy = new FieldPolicy(['updatedAt'], []);
        $resource = new AuditedResource('product', ProductInterface::class, ignoredFields: ['position']);

        self::assertTrue($policy->isIgnored('updatedAt'));
        self::assertTrue($policy->isIgnored('position', $resource));
        self::assertFalse($policy->isIgnored('position'));
        self::assertFalse($policy->isIgnored('code', $resource));
    }

    public function testMaskedFieldsAreCaseInsensitiveAndPatternsApply(): void
    {
        $policy = new FieldPolicy([], ['details'], ['/token/i', '/password/i']);

        self::assertTrue($policy->isMasked('details'));
        self::assertTrue($policy->isMasked('Details'));
        self::assertTrue($policy->isMasked('passwordResetToken'));
        self::assertTrue($policy->isMasked('emailVerificationToken'));
        self::assertTrue($policy->isMasked('plainPassword'));
        self::assertFalse($policy->isMasked('email'));
    }

    public function testTheDefaultsMaskSecretsButNotBusinessFields(): void
    {
        $policy = new FieldPolicy(Configuration::DEFAULT_IGNORED_FIELDS, Configuration::DEFAULT_MASKED_FIELDS, Configuration::DEFAULT_MASKED_FIELD_PATTERNS);

        foreach (['password', 'salt', 'tokenValue', 'passwordResetToken', 'emailVerificationToken', 'details', 'config', 'apiKey', 'clientSecret'] as $field) {
            self::assertTrue($policy->isMasked($field), $field);
        }
        foreach (['price', 'name', 'onHand', 'state', 'email', 'enabled'] as $field) {
            self::assertFalse($policy->isMasked($field), $field);
        }
        foreach (['createdAt', 'updatedAt', 'lastLogin', 'emailCanonical'] as $field) {
            self::assertTrue($policy->isIgnored($field), $field);
        }
    }

    public function testAnInvalidPatternDoesNotBreakRecording(): void
    {
        $policy = new FieldPolicy([], [], ['/[unclosed']);

        self::assertFalse($policy->isMasked('name'));
    }
}
