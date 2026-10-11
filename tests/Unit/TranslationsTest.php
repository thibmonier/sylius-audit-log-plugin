<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tmonier\SyliusAuditLogPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pl'];

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleDefinesTheSameKeys(string $locale): void
    {
        $reference = $this->flatten($this->parse('en'));
        $catalogue = $this->flatten($this->parse($locale));

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($catalogue))), 'Missing keys');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($reference))), 'Unknown keys');
        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('Empty translation "%s"', $key));
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            preg_match_all('/%\w+%/', $value, $actual);
            self::assertEqualsCanonicalizing($expected[0], $actual[0], sprintf('Placeholders of "%s"', $key));
        }
    }

    public function testEveryDefaultResourceActionActorAndSourceHasALabel(): void
    {
        $messages = $this->flatten($this->parse('en'));

        foreach (array_keys(Configuration::DEFAULT_RESOURCES) as $resource) {
            self::assertArrayHasKey('tmonier_audit_log.resource.' . $resource, $messages);
        }
        foreach (AuditLogEntryInterface::ACTIONS as $action) {
            self::assertArrayHasKey('tmonier_audit_log.ui.action_type.' . $action, $messages);
        }
        foreach (AuditLogEntryInterface::ACTOR_TYPES as $actor) {
            self::assertArrayHasKey('tmonier_audit_log.ui.actor_type.' . $actor, $messages);
        }
        foreach (AuditLogEntryInterface::SOURCES as $source) {
            self::assertArrayHasKey('tmonier_audit_log.ui.source.' . $source, $messages);
        }
    }

    /** @return array<mixed> */
    private function parse(string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/../../translations/messages.%s.yaml', __DIR__, $locale));
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}
