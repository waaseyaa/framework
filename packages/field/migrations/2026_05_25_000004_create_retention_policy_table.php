<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materialises the classification-driven `retention_policy` entity table.
 *
 * Sibling of the `audit_retention_policy` table in `packages/audit`; both
 * encode retention rules but cover different surfaces (audit-log vs.
 * non-audit entities). See spec.md §"Decisions pre-resolved".
 *
 * Idempotent: safe to re-run if the table already exists.
 *
 * Refs: FR-005, FR-008, NFR-002.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('retention_policy')) {
            $conn->executeStatement(
                'CREATE TABLE retention_policy (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                    name VARCHAR(255) NOT NULL DEFAULT \'\',
                    applies_to TEXT NOT NULL DEFAULT \'[]\',
                    action VARCHAR(32) NOT NULL DEFAULT \'\',
                    trigger_kind VARCHAR(32) NOT NULL DEFAULT \'\',
                    trigger_value VARCHAR(255) NOT NULL DEFAULT \'\',
                    exemptions TEXT NOT NULL DEFAULT \'[]\',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            );

            $conn->executeStatement(
                'CREATE UNIQUE INDEX retention_policy_uuid ON retention_policy (uuid)',
            );

            $conn->executeStatement(
                'CREATE INDEX retention_policy_action ON retention_policy (action)',
            );

            $conn->executeStatement(
                'CREATE INDEX retention_policy_trigger_kind ON retention_policy (trigger_kind)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: no-op on down.
    }
};
