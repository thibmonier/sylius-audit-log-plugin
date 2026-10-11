<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusAuditLogPlugin\Command\PurgeAuditLogCommand;

final class PurgeLimitTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ages(): iterable
    {
        yield 'days with suffix' => ['365d', '2025-10-11 12:00:00'];
        yield 'plain days' => ['30', '2026-09-11 12:00:00'];
        yield 'months' => ['6 months', '2026-04-11 12:00:00'];
        yield 'year' => ['1 year', '2025-10-11 12:00:00'];
        yield 'weeks' => ['2 weeks', '2026-09-27 12:00:00'];
    }

    #[DataProvider('ages')]
    public function testAgeLimits(string $olderThan, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame($expected, PurgeAuditLogCommand::limit($now, $olderThan)->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAges(): iterable
    {
        yield 'zero' => ['0d'];
        yield 'garbage' => ['yesterday'];
        yield 'negative' => ['-5d'];
    }

    #[DataProvider('invalidAges')]
    public function testInvalidAgeLimits(string $olderThan): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PurgeAuditLogCommand::limit(new \DateTimeImmutable(), $olderThan);
    }
}
