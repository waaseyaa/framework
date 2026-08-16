<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** DB-02-owned non-secret application-master rekey coordination and evidence. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('waaseyaa_application_master_rekey')) {
            return;
        }

        $connection = $schema->getConnection();
        foreach ($this->statements() as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only custody evidence migration.
    }

    /** @return list<string> */
    private function statements(): array
    {
        return [
            'CREATE TABLE waaseyaa_application_master_rekey (
                request_id TEXT PRIMARY KEY,
                request_digest TEXT NOT NULL UNIQUE,
                from_version INTEGER NOT NULL,
                to_version INTEGER NOT NULL,
                registry_checksum TEXT NOT NULL,
                authorization_digest TEXT NOT NULL,
                actor TEXT NOT NULL,
                rollback_deadline INTEGER NOT NULL,
                retention_deadline INTEGER NOT NULL,
                state TEXT NOT NULL,
                revision INTEGER NOT NULL,
                unresolved_failures INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                CHECK (from_version > 0 AND to_version > from_version),
                CHECK (retention_deadline >= rollback_deadline),
                CHECK (revision > 0 AND unresolved_failures >= 0)
            )',
            "CREATE TABLE waaseyaa_application_master_version (
                master_version INTEGER PRIMARY KEY,
                state TEXT NOT NULL,
                reference_fingerprint TEXT NOT NULL UNIQUE,
                activated_at INTEGER,
                retained_until INTEGER,
                revoked_at INTEGER,
                CHECK (master_version > 0),
                CHECK (state IN ('active-write', 'legacy-read-verify', 'failed-read-only', 'revoked'))
            )",
            'CREATE TABLE waaseyaa_application_master_rekey_purpose (
                request_id TEXT NOT NULL,
                purpose_id TEXT NOT NULL,
                canonical_policy_json TEXT NOT NULL,
                policy_checksum TEXT NOT NULL,
                PRIMARY KEY (request_id, purpose_id),
                FOREIGN KEY (request_id) REFERENCES waaseyaa_application_master_rekey(request_id)
            )',
            "CREATE TABLE waaseyaa_application_master_rekey_adapter (
                request_id TEXT NOT NULL,
                adapter_id TEXT NOT NULL,
                purpose_ids_json TEXT NOT NULL,
                snapshot_token TEXT NOT NULL,
                total_records INTEGER NOT NULL,
                cursor TEXT,
                transitioned_records INTEGER NOT NULL DEFAULT 0,
                purpose_counts_json TEXT NOT NULL,
                batch_chain_hash TEXT NOT NULL,
                status TEXT NOT NULL,
                rollback_snapshot_token TEXT,
                rollback_total_records INTEGER NOT NULL DEFAULT 0,
                rollback_cursor TEXT,
                rolled_back_records INTEGER NOT NULL DEFAULT 0,
                rollback_purpose_counts_json TEXT NOT NULL,
                rollback_chain_hash TEXT NOT NULL,
                rollback_status TEXT NOT NULL,
                revision INTEGER NOT NULL,
                unresolved_failures INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (request_id, adapter_id),
                FOREIGN KEY (request_id) REFERENCES waaseyaa_application_master_rekey(request_id),
                CHECK (total_records >= 0 AND transitioned_records >= 0
                    AND rollback_total_records >= 0 AND rolled_back_records >= 0),
                CHECK (revision > 0 AND unresolved_failures >= 0),
                CHECK (status IN ('snapshotted', 'transitioning', 'complete')),
                CHECK (rollback_status IN ('pending', 'snapshotted', 'rolling-back', 'complete'))
            )",
            'CREATE TABLE waaseyaa_application_master_rekey_failure (
                failure_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                adapter_id TEXT NOT NULL,
                cursor TEXT,
                failure_code TEXT NOT NULL,
                evidence_hash TEXT NOT NULL,
                opened_at INTEGER NOT NULL,
                resolved_at INTEGER,
                resolution_hash TEXT,
                FOREIGN KEY (request_id) REFERENCES waaseyaa_application_master_rekey(request_id),
                CHECK ((resolved_at IS NULL AND resolution_hash IS NULL)
                    OR (resolved_at IS NOT NULL AND resolution_hash IS NOT NULL))
            )',
            'CREATE UNIQUE INDEX waaseyaa_app_master_failure_open_idx
                ON waaseyaa_application_master_rekey_failure (request_id, adapter_id)
                WHERE resolved_at IS NULL',
            'CREATE TABLE waaseyaa_application_master_rekey_verification (
                request_id TEXT NOT NULL,
                purpose_id TEXT NOT NULL,
                verified_records INTEGER NOT NULL,
                verification_hash TEXT NOT NULL,
                recorded_at INTEGER NOT NULL,
                PRIMARY KEY (request_id, purpose_id),
                FOREIGN KEY (request_id, purpose_id)
                    REFERENCES waaseyaa_application_master_rekey_purpose(request_id, purpose_id),
                CHECK (verified_records >= 0)
            )',
            'CREATE TABLE waaseyaa_application_master_rekey_rollback_verification (
                request_id TEXT NOT NULL,
                purpose_id TEXT NOT NULL,
                verified_records INTEGER NOT NULL,
                verification_hash TEXT NOT NULL,
                recorded_at INTEGER NOT NULL,
                PRIMARY KEY (request_id, purpose_id),
                FOREIGN KEY (request_id, purpose_id)
                    REFERENCES waaseyaa_application_master_rekey_purpose(request_id, purpose_id),
                CHECK (verified_records >= 0)
            )',
            'CREATE TABLE waaseyaa_application_master_rekey_gate (
                request_id TEXT NOT NULL,
                gate_id TEXT NOT NULL,
                evidence_hash TEXT NOT NULL,
                recorded_at INTEGER NOT NULL,
                PRIMARY KEY (request_id, gate_id),
                FOREIGN KEY (request_id) REFERENCES waaseyaa_application_master_rekey(request_id)
            )',
            'CREATE TABLE waaseyaa_application_master_rekey_event (
                event_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                sequence INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                body_json TEXT NOT NULL,
                previous_hash TEXT NOT NULL,
                event_hash TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                UNIQUE (request_id, sequence),
                FOREIGN KEY (request_id) REFERENCES waaseyaa_application_master_rekey(request_id),
                CHECK (sequence > 0)
            )',
            'CREATE INDEX waaseyaa_app_master_event_request_idx ON waaseyaa_application_master_rekey_event (request_id, sequence)',
            "CREATE TRIGGER waaseyaa_app_master_event_no_update
                BEFORE UPDATE ON waaseyaa_application_master_rekey_event
                BEGIN SELECT RAISE(ABORT, 'application-master rekey events are append-only'); END",
            "CREATE TRIGGER waaseyaa_app_master_event_no_delete
                BEFORE DELETE ON waaseyaa_application_master_rekey_event
                BEGIN SELECT RAISE(ABORT, 'application-master rekey events are append-only'); END",
        ];
    }
};
