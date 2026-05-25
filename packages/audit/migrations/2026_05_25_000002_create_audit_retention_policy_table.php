<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materialises the audit_retention_policy entity table.
 *
 * Each row defines a rule: events matching kind_pattern older than
 * older_than_seconds seconds are eligible for the configured action (purge).
 *
 * The `audit:prune` CLI command reads these rows and executes them.
 * Each execution is itself audit-logged (FR-012 — self-referential audit).
 *
 * Idempotent: safe to re-run if the table already exists.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('audit_retention_policy')) {
            $conn->executeStatement(
                'CREATE TABLE audit_retention_policy (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                    kind_pattern VARCHAR(64) NOT NULL DEFAULT \'*\',
                    older_than_seconds INTEGER NOT NULL DEFAULT 0,
                    action VARCHAR(16) NOT NULL DEFAULT \'purge\',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            );

            $conn->executeStatement(
                'CREATE UNIQUE INDEX audit_retention_policy_uuid ON audit_retention_policy (uuid)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: no-op.
    }
};
