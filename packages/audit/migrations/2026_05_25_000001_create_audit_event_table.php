<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materialises the audit_event append-only entity table.
 *
 * Schema per FR-002 (ocap-audit-log-substrate-01KSEFTF):
 *   id, uuid, event_kind, account_uid, entity_type_id, entity_uuid,
 *   subject_uri, outcome, severity, attributes (JSON), created_at.
 *
 * Indices:
 *   audit_event_uuid           — unique (uuid)
 *   audit_event_account_uid    — (account_uid)
 *   audit_event_entity         — (entity_type_id, entity_uuid)
 *   audit_event_kind_time      — (event_kind, created_at)
 *   audit_event_created_at     — (created_at)
 *
 * Single-table — NOT two-axis. Audit events are immutable historical facts
 * and are never revised or translated (C-001).
 *
 * Idempotent: safe to re-run if the table already exists.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('audit_event')) {
            $conn->executeStatement(
                'CREATE TABLE audit_event (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                    event_kind VARCHAR(64) NOT NULL DEFAULT \'\',
                    account_uid INTEGER NOT NULL DEFAULT 0,
                    entity_type_id VARCHAR(128) NOT NULL DEFAULT \'\',
                    entity_uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                    subject_uri VARCHAR(512) NOT NULL DEFAULT \'\',
                    outcome VARCHAR(16) NOT NULL DEFAULT \'allowed\',
                    severity VARCHAR(16) NOT NULL DEFAULT \'info\',
                    attributes TEXT NOT NULL DEFAULT \'{}\',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            );

            $conn->executeStatement(
                'CREATE UNIQUE INDEX audit_event_uuid ON audit_event (uuid)',
            );
            $conn->executeStatement(
                'CREATE INDEX audit_event_account_uid ON audit_event (account_uid)',
            );
            $conn->executeStatement(
                'CREATE INDEX audit_event_entity ON audit_event (entity_type_id, entity_uuid)',
            );
            $conn->executeStatement(
                'CREATE INDEX audit_event_kind_time ON audit_event (event_kind, created_at)',
            );
            $conn->executeStatement(
                'CREATE INDEX audit_event_created_at ON audit_event (created_at)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping tables is version-dependent; left as no-op.
    }
};
