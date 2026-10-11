<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Grid;

use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;

/**
 * Filters entries on a resource type and/or id. With an id, the entries of the resources owned
 * by it match too (variants and prices of a product, payments of an order...).
 */
final class ResourceFilter implements FilterInterface
{
    public const TYPE = 'tmonier_audit_log_resource';

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (!is_array($data)) {
            return;
        }

        $type = is_string($data['type'] ?? null) ? trim($data['type']) : '';
        $id = is_scalar($data['id'] ?? null) ? trim((string) $data['id']) : '';
        $expr = $dataSource->getExpressionBuilder();

        if ('' !== $type && '' !== $id) {
            $dataSource->restrict($expr->orX(
                $expr->andX($expr->equals('resourceType', $type), $expr->equals('resourceId', $id)),
                $expr->andX($expr->equals('ownerType', $type), $expr->equals('ownerId', $id)),
            ));

            return;
        }

        if ('' !== $type) {
            $dataSource->restrict($expr->equals('resourceType', $type));
        }

        if ('' !== $id) {
            $dataSource->restrict($expr->orX($expr->equals('resourceId', $id), $expr->equals('ownerId', $id)));
        }
    }
}
