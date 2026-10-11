<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Tests\Tmonier\SyliusAuditLogPlugin\Unit\Fixture\Status;
use Tests\Tmonier\SyliusAuditLogPlugin\Unit\Fixture\Suit;
use Tmonier\SyliusAuditLogPlugin\Diff\ObjectReference;
use Tmonier\SyliusAuditLogPlugin\Diff\ValueNormalizer;

final class ValueNormalizerTest extends TestCase
{
    public function testScalarsAreKept(): void
    {
        $normalizer = new ValueNormalizer();
        $noReference = static fn (object $object): ?ObjectReference => null;

        self::assertNull($normalizer->normalize(null, $noReference));
        self::assertTrue($normalizer->normalize(true, $noReference));
        self::assertSame(1990, $normalizer->normalize(1990, $noReference));
        self::assertSame(19.9, $normalizer->normalize(19.9, $noReference));
        self::assertSame('INF', $normalizer->normalize(\INF, $noReference));
        self::assertSame('Wool hat', $normalizer->normalize('Wool hat', $noReference));
    }

    public function testLongTextIsTruncatedWithAnEllipsis(): void
    {
        $normalizer = new ValueNormalizer(20);

        $value = $normalizer->normalize(str_repeat('é', 50), static fn (object $object): ?ObjectReference => null);

        self::assertIsString($value);
        self::assertSame(20, mb_strlen($value));
        self::assertStringEndsWith(ValueNormalizer::ELLIPSIS, $value);
        self::assertSame('short', $normalizer->truncate('short'));
    }

    public function testDatesEnumsAndArrays(): void
    {
        $normalizer = new ValueNormalizer(40);
        $noReference = static fn (object $object): ?ObjectReference => null;

        self::assertSame('2026-10-11T08:30:00+00:00', $normalizer->normalize(new \DateTimeImmutable('2026-10-11 08:30:00', new \DateTimeZone('UTC')), $noReference));
        self::assertSame('active', $normalizer->normalize(Status::Active, $noReference));
        self::assertSame('Hearts', $normalizer->normalize(Suit::Hearts, $noReference));
        self::assertSame('{"amount":1000,"channel":"WEB"}', $normalizer->normalize(['amount' => 1000, 'channel' => 'WEB'], $noReference));
        $encoded = $normalizer->normalize(range(1, 100), $noReference);
        self::assertIsString($encoded);
        self::assertSame(40, mb_strlen($encoded));
    }

    public function testEntitiesBecomeReferencesAndOtherObjectsAreDescribed(): void
    {
        $normalizer = new ValueNormalizer();
        $entity = new \stdClass();
        $reference = new ObjectReference($entity, '12', 'Fashion Web Store');

        self::assertSame($reference, $normalizer->normalize($entity, static fn (object $object): ?ObjectReference => $object === $entity ? $reference : null));
        self::assertSame('stdClass', $normalizer->normalize(new \stdClass(), static fn (object $object): ?ObjectReference => null));

        $stringable = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };
        self::assertSame('stringable', $normalizer->normalize($stringable, static fn (object $object): ?ObjectReference => null));
    }
}
