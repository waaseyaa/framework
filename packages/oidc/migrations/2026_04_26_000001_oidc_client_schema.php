<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materializes the oidc_client entity table and indexed lookup columns.
 *
 * Runs on paths that do not boot the full entity storage factory (e.g. db:init)
 * and supplements ensureTable() output (base columns only) on full kernel boots
 * after migrate.
 *
 * Idempotent: safe to re-run if columns or table already exist.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('oidc_client')) {
            $conn->executeStatement(
                'CREATE TABLE oidc_client (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    uuid TEXT NOT NULL DEFAULT \'\',
                    bundle TEXT NOT NULL DEFAULT \'\',
                    name TEXT NOT NULL DEFAULT \'\',
                    langcode TEXT NOT NULL DEFAULT \'en\',
                    _data TEXT NOT NULL DEFAULT \'{}\'
                )',
            );
            $conn->executeStatement('CREATE UNIQUE INDEX oidc_client_uuid ON oidc_client (uuid)');
            $conn->executeStatement('CREATE INDEX oidc_client_bundle ON oidc_client (bundle)');
        }

        $this->addColumnIfMissing($schema, 'oidc_client', 'client_id', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($schema, 'oidc_client', 'name', 'TEXT NOT NULL DEFAULT \'\'');
        $this->addColumnIfMissing($schema, 'oidc_client', 'is_confidential', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($schema, 'oidc_client', 'client_secret_hash', 'VARCHAR(255) NULL');
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping columns is version-dependent; leave no-op.
    }

    private function addColumnIfMissing(SchemaBuilder $schema, string $table, string $column, string $sqliteFragment): void
    {
        if ($schema->hasColumn($table, $column)) {
            return;
        }

        $schema->getConnection()->executeStatement(
            sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $sqliteFragment),
        );
    }
};
