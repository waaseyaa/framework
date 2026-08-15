<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $database = $schema->getConnection();
        $database->executeStatement(
            'CREATE TABLE IF NOT EXISTS audit_checkpoint_succession ('
            . 'sequence INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'record_kind TEXT NOT NULL, format TEXT NOT NULL, purpose_id TEXT NOT NULL, algorithm TEXT NOT NULL, '
            . 'from_master_version INTEGER NOT NULL, to_master_version INTEGER NOT NULL, '
            . 'predecessor_key_id TEXT NOT NULL, successor_key_id TEXT NOT NULL, '
            . 'chain_digest TEXT NOT NULL, pruned_checkpoint_digest TEXT NOT NULL, '
            . 'pruned_checkpoint_count INTEGER NOT NULL, checkpoint_count INTEGER NOT NULL, '
            . 'terminal_checkpoint_id INTEGER NOT NULL, terminal_checkpoint_uuid TEXT NOT NULL, '
            . 'terminal_segment_end_id INTEGER NOT NULL, terminal_checkpoint_hash TEXT NOT NULL, '
            . 'rekey_request_digest TEXT NOT NULL, registry_checksum TEXT NOT NULL, '
            . 'rolled_back_sequence INTEGER NULL, signature TEXT NOT NULL, '
            . 'previous_ledger_hash TEXT NOT NULL, record_hash TEXT NOT NULL, created_at INTEGER NOT NULL, '
            . 'UNIQUE (record_hash), UNIQUE (rekey_request_digest, record_kind), '
            . "CHECK (record_kind IN ('succession-anchor', 'succession-rollback')), "
            . 'CHECK (from_master_version > 0 AND to_master_version > from_master_version), '
            . 'CHECK (checkpoint_count >= 0 AND pruned_checkpoint_count >= 0 AND terminal_checkpoint_id >= 0), '
            . "CHECK ((record_kind = 'succession-anchor' AND rolled_back_sequence IS NULL) "
            . "OR (record_kind = 'succession-rollback' AND rolled_back_sequence IS NOT NULL)), "
            . 'FOREIGN KEY (rolled_back_sequence) REFERENCES audit_checkpoint_succession(sequence))',
        );
        $database->executeStatement(
            'CREATE INDEX IF NOT EXISTS audit_checkpoint_succession_versions '
            . 'ON audit_checkpoint_succession (from_master_version, to_master_version)',
        );
        $database->executeStatement(
            'CREATE TABLE IF NOT EXISTS audit_checkpoint_succession_pruned ('
            . 'anchor_sequence INTEGER NOT NULL, checkpoint_id INTEGER NOT NULL, '
            . 'checkpoint_uuid TEXT NOT NULL, checkpoint_hash TEXT NOT NULL, '
            . 'PRIMARY KEY (anchor_sequence, checkpoint_id), '
            . 'FOREIGN KEY (anchor_sequence) REFERENCES audit_checkpoint_succession(sequence), '
            . 'FOREIGN KEY (checkpoint_id) REFERENCES audit_checkpoint(id))',
        );
        $database->executeStatement(
            "CREATE TRIGGER IF NOT EXISTS audit_checkpoint_succession_no_update BEFORE UPDATE ON audit_checkpoint_succession BEGIN SELECT RAISE(ABORT, 'audit checkpoint succession records are append-only'); END",
        );
        $database->executeStatement(
            "CREATE TRIGGER IF NOT EXISTS audit_checkpoint_succession_no_delete BEFORE DELETE ON audit_checkpoint_succession BEGIN SELECT RAISE(ABORT, 'audit checkpoint succession records are append-only'); END",
        );
        $database->executeStatement(
            "CREATE TRIGGER IF NOT EXISTS audit_checkpoint_succession_pruned_no_update BEFORE UPDATE ON audit_checkpoint_succession_pruned BEGIN SELECT RAISE(ABORT, 'audit checkpoint succession pruned evidence is append-only'); END",
        );
        $database->executeStatement(
            "CREATE TRIGGER IF NOT EXISTS audit_checkpoint_succession_pruned_no_delete BEFORE DELETE ON audit_checkpoint_succession_pruned BEGIN SELECT RAISE(ABORT, 'audit checkpoint succession pruned evidence is append-only'); END",
        );
    }

    public function down(SchemaBuilder $schema): void {}
};
