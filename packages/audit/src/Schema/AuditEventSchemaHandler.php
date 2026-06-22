<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Ensures the `audit_event` and `audit_retention_policy` tables exist.
 *
 * Called from {@see \Waaseyaa\Audit\AuditServiceProvider::boot()} on each
 * kernel boot. Idempotent: uses `CREATE TABLE IF NOT EXISTS` and
 * `CREATE INDEX IF NOT EXISTS`.
 *
 * Schema rationale:
 * - Single table (not two-axis) — audit events are immutable historical facts,
 *   never revised or translated (C-001).
 * - Indices on (account_uid), (actor_uid), (entity_type_id, entity_uuid),
 *   (event_kind, created_at), (created_at) support the canonical query
 *   patterns defined in {@see \Waaseyaa\Audit\Contract\AuditQuery}.
 * - `actor_uid` (nullable INTEGER, no default) is the authoritative three-state
 *   actor column (account N / anonymous 0 / SQL NULL = no acting context). It
 *   ships in the CREATE TABLE for new installs and is added to pre-existing
 *   installs by an idempotent guarded ALTER (column-existence probe first) —
 *   additive only, no existing column or row changes (C-002/C-004).
 *   `account_uid` stays NOT NULL as the legacy `actor ?? 0` compat column.
 *
 * @api
 */
final class AuditEventSchemaHandler
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function ensureSchema(): void
    {
        if (!$this->database instanceof DBALDatabase) {
            return;
        }

        $conn = $this->database->getConnection();

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS audit_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                event_kind VARCHAR(64) NOT NULL DEFAULT \'\',
                account_uid INTEGER NOT NULL DEFAULT 0,
                actor_uid INTEGER NULL,
                entity_type_id VARCHAR(128) NOT NULL DEFAULT \'\',
                entity_uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                subject_uri VARCHAR(512) NOT NULL DEFAULT \'\',
                outcome VARCHAR(16) NOT NULL DEFAULT \'allowed\',
                severity VARCHAR(16) NOT NULL DEFAULT \'info\',
                attributes TEXT NOT NULL DEFAULT \'{}\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        // Additive migration (mission revision-audit-provenance, FR-004 enabler):
        // pre-mission installs created audit_event without actor_uid. ADD COLUMN
        // of a nullable, no-default column is metadata-only DDL on SQLite/MySQL 8/
        // PostgreSQL — no table rewrite, safe on an append-only log. Probe column
        // existence first so the ALTER runs exactly once (C-002/C-004); rows that
        // predate the column read back as SQL NULL ("no acting context").
        if (!$this->database->schema()->fieldExists('audit_event', 'actor_uid')) {
            $conn->executeStatement(
                'ALTER TABLE audit_event ADD COLUMN actor_uid INTEGER',
            );
        }

        $conn->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS audit_event_uuid ON audit_event (uuid)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_event_account_uid ON audit_event (account_uid)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_event_actor_uid ON audit_event (actor_uid)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_event_entity ON audit_event (entity_type_id, entity_uuid)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_event_kind_time ON audit_event (event_kind, created_at)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_event_created_at ON audit_event (created_at)',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS audit_retention_policy (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                kind_pattern VARCHAR(64) NOT NULL DEFAULT \'*\',
                older_than_seconds INTEGER NOT NULL DEFAULT 0,
                action VARCHAR(16) NOT NULL DEFAULT \'purge\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        $conn->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS audit_retention_policy_uuid ON audit_retention_policy (uuid)',
        );
    }
}
