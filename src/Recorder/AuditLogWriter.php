<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Recorder;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/**
 * Inserts entries with DBAL from their ORM mapping (custom entry classes and extra fields are
 * supported). No ORM persist: writing never triggers another flush, so no recursion.
 */
final class AuditLogWriter
{
    /** @param list<AuditLogEntryInterface> $entries */
    public function write(EntityManagerInterface $entityManager, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        $connection = $entityManager->getConnection();
        // A savepoint when the flush ran inside a transaction: a failing insert must not
        // abort the caller's transaction (PostgreSQL would).
        $connection->beginTransaction();

        try {
            foreach ($entries as $entry) {
                $metadata = $entityManager->getClassMetadata($entry::class);
                $data = [];
                $types = [];
                foreach ($metadata->getFieldNames() as $field) {
                    $value = $metadata->getFieldValue($entry, $field);
                    if (null === $value && $metadata->isIdentifier($field)) {
                        continue;
                    }

                    $column = $metadata->getColumnName($field);
                    $data[$column] = $value;
                    $types[$column] = $metadata->getTypeOfField($field) ?? 'string';
                }

                $connection->insert($metadata->getTableName(), $data, $types);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
