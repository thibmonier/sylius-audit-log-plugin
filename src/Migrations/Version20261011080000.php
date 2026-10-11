<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261011080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the audit log entry table (tmonier/sylius-audit-log-plugin).';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->createTable('tmonier_audit_log_entry');
        $entry->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entry->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $entry->addColumn('action', Types::STRING, ['length' => 16]);
        $entry->addColumn('resource_type', Types::STRING, ['length' => 64]);
        $entry->addColumn('resource_class', Types::STRING, ['length' => 255]);
        $entry->addColumn('resource_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('resource_label', Types::STRING, ['length' => 255, 'notnull' => false]);
        $entry->addColumn('owner_type', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('owner_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('changes', Types::JSON);
        $entry->addColumn('actor_type', Types::STRING, ['length' => 16]);
        $entry->addColumn('actor_id', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('actor_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $entry->addColumn('source', Types::STRING, ['length' => 16]);
        $entry->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $entry->addColumn('request_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('route', Types::STRING, ['length' => 255, 'notnull' => false]);
        $entry->setPrimaryKey(['id']);
        $entry->addIndex(['created_at'], 'tmonier_audit_log_entry_created_at_idx');
        $entry->addIndex(['owner_type', 'owner_id', 'created_at'], 'tmonier_audit_log_entry_owner_idx');
        $entry->addIndex(['resource_type', 'resource_id'], 'tmonier_audit_log_entry_resource_idx');
        $entry->addIndex(['actor_type', 'actor_id'], 'tmonier_audit_log_entry_actor_idx');
        $entry->addIndex(['request_id'], 'tmonier_audit_log_entry_request_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_audit_log_entry');
    }
}
