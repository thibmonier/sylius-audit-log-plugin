<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAuditLogPlugin\Unit\Grid;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Data\ExpressionBuilderInterface;
use Tmonier\SyliusAuditLogPlugin\Grid\ResourceFilter;

final class ResourceFilterTest extends TestCase
{
    public function testTypeAndIdMatchTheResourceOrItsOwner(): void
    {
        self::assertSame(
            ['((resourceType = product AND resourceId = 5) OR (ownerType = product AND ownerId = 5))'],
            $this->apply(['type' => 'product', 'id' => ' 5 ']),
        );
    }

    public function testTypeOnlyAndIdOnly(): void
    {
        self::assertSame(['resourceType = order'], $this->apply(['type' => 'order', 'id' => '']));
        self::assertSame(['(resourceId = 9 OR ownerId = 9)'], $this->apply(['type' => '', 'id' => '9']));
    }

    public function testEmptyDataDoesNotFilter(): void
    {
        self::assertSame([], $this->apply(['type' => null, 'id' => null]));
        self::assertSame([], $this->apply('product'));
    }

    /** @return list<string> */
    private function apply(mixed $data): array
    {
        $expressionBuilder = $this->createMock(ExpressionBuilderInterface::class);
        $expressionBuilder->method('equals')->willReturnCallback(static fn (string $field, mixed $value): string => sprintf('%s = %s', $field, is_scalar($value) ? (string) $value : ''));
        $expressionBuilder->method('andX')->willReturnCallback(static fn (string ...$expressions): string => '(' . implode(' AND ', $expressions) . ')');
        $expressionBuilder->method('orX')->willReturnCallback(static fn (string ...$expressions): string => '(' . implode(' OR ', $expressions) . ')');

        $restrictions = [];
        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getExpressionBuilder')->willReturn($expressionBuilder);
        $dataSource->method('restrict')->willReturnCallback(static function (mixed $expression) use (&$restrictions): void {
            $restrictions[] = is_string($expression) ? $expression : '';
        });

        (new ResourceFilter())->apply($dataSource, 'resource', $data, []);

        return $restrictions;
    }
}
