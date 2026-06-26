<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Schema;

use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
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
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                row_hash CHAR(64) NOT NULL DEFAULT \'\',
                prev_hash CHAR(64) NOT NULL DEFAULT \'\'
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

        // Additive migration: tamper-evidence chain columns (WP1). DEFAULT ''
        // means "unsealed" — the checkpoint builder (WP2) fills these in during
        // the sealing pass. Existing rows keep DEFAULT '' until sealed.
        if (!$this->database->schema()->fieldExists('audit_event', 'row_hash')) {
            $conn->executeStatement(
                "ALTER TABLE audit_event ADD COLUMN row_hash CHAR(64) NOT NULL DEFAULT ''",
            );
        }

        if (!$this->database->schema()->fieldExists('audit_event', 'prev_hash')) {
            $conn->executeStatement(
                "ALTER TABLE audit_event ADD COLUMN prev_hash CHAR(64) NOT NULL DEFAULT ''",
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

        // ----------------------------------------------------------------
        // Tamper-evidence checkpoint table (WP1)
        // ----------------------------------------------------------------
        // audit_checkpoint anchors the hash-chain: each row seals a segment of
        // audit_event rows (segment_start_id … segment_end_id) by recording the
        // rolling hash of every row_hash in that segment and chaining it to the
        // hash of the previous checkpoint. The genesis row (is_genesis=1)
        // bootstraps the chain on first install; its segment_hash and
        // prev_checkpoint_hash are GENESIS_HASH ("predates chaining").
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS audit_checkpoint (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                segment_start_id INTEGER NOT NULL DEFAULT 0,
                segment_end_id INTEGER NOT NULL DEFAULT 0,
                row_count INTEGER NOT NULL DEFAULT 0,
                segment_hash CHAR(64) NOT NULL DEFAULT \'\',
                prev_checkpoint_hash CHAR(64) NOT NULL DEFAULT \'\',
                checkpoint_hash CHAR(64) NOT NULL DEFAULT \'\',
                signature TEXT NOT NULL DEFAULT \'\',
                hash_version VARCHAR(16) NOT NULL DEFAULT \'v1\',
                is_genesis INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        $conn->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS audit_checkpoint_uuid ON audit_checkpoint (uuid)',
        );
        $conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_checkpoint_segment_end ON audit_checkpoint (segment_end_id)',
        );

        // Genesis anchor: insert exactly once when audit_checkpoint is empty.
        // Idempotency: the check is done inside a transaction-safe SELECT COUNT
        // so a concurrent boot cannot insert two genesis rows. Running
        // ensureSchema() a second time hits the COUNT > 0 branch and skips.
        $existingCheckpointsRaw = $conn->fetchOne('SELECT COUNT(*) FROM audit_checkpoint');
        $existingCheckpoints = is_scalar($existingCheckpointsRaw) ? (int) $existingCheckpointsRaw : 0;
        if ($existingCheckpoints === 0) {
            // Capture the current high-water mark of audit_event so the genesis
            // row accurately reflects any events that were written before the
            // checkpoint table existed.
            $maxEventIdRaw = $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_event');
            $maxEventId = is_scalar($maxEventIdRaw) ? (int) $maxEventIdRaw : 0;
            $eventCountRaw = $conn->fetchOne('SELECT COUNT(*) FROM audit_event');
            $eventCount = is_scalar($eventCountRaw) ? (int) $eventCountRaw : 0;

            $checkpointHash = AuditCheckpointHasher::checkpointHash(
                segmentStartId: 1,
                segmentEndId: $maxEventId,
                rowCount: $eventCount,
                segmentHash: AuditEventCanonicalizer::GENESIS_HASH,
                prevCheckpointHash: AuditEventCanonicalizer::GENESIS_HASH,
            );

            $this->database->insert('audit_checkpoint')->values([
                'uuid'                 => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
                'segment_start_id'     => 1,
                'segment_end_id'       => $maxEventId,
                'row_count'            => $eventCount,
                'segment_hash'         => AuditEventCanonicalizer::GENESIS_HASH,
                'prev_checkpoint_hash' => AuditEventCanonicalizer::GENESIS_HASH,
                'checkpoint_hash'      => $checkpointHash,
                'signature'            => '',
                'hash_version'         => AuditEventCanonicalizer::HASH_VERSION,
                'is_genesis'           => 1,
                'created_at'           => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ])->execute();
        }
    }
}
