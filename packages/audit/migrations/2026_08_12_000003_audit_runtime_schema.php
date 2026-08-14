<?php

declare(strict_types=1);

use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Installs and upgrades the durable core audit schema under coordinator authority. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $db = $schema->getConnection();
        $db->executeStatement("CREATE TABLE IF NOT EXISTS audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(128) NOT NULL DEFAULT '', event_kind VARCHAR(64) NOT NULL DEFAULT '', account_uid INTEGER NOT NULL DEFAULT 0, actor_uid INTEGER NULL, entity_type_id VARCHAR(128) NOT NULL DEFAULT '', entity_uuid VARCHAR(128) NOT NULL DEFAULT '', subject_uri VARCHAR(512) NOT NULL DEFAULT '', outcome VARCHAR(16) NOT NULL DEFAULT 'allowed', severity VARCHAR(16) NOT NULL DEFAULT 'info', attributes TEXT NOT NULL DEFAULT '{}', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, row_hash CHAR(64) NOT NULL DEFAULT '', prev_hash CHAR(64) NOT NULL DEFAULT '')");
        foreach (['actor_uid' => 'INTEGER', 'row_hash' => "CHAR(64) NOT NULL DEFAULT ''", 'prev_hash' => "CHAR(64) NOT NULL DEFAULT ''"] as $field => $definition) {
            if (!$schema->hasColumn('audit_event', $field)) {
                $db->executeStatement("ALTER TABLE audit_event ADD COLUMN {$field} {$definition}");
            }
        }
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS audit_event_uuid ON audit_event (uuid)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_event_account_uid ON audit_event (account_uid)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_event_actor_uid ON audit_event (actor_uid)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_event_entity ON audit_event (entity_type_id, entity_uuid)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_event_kind_time ON audit_event (event_kind, created_at)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_event_created_at ON audit_event (created_at)');

        $db->executeStatement('CREATE TABLE IF NOT EXISTS privileged_read_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, receipt_id VARCHAR(128) NOT NULL, event_type VARCHAR(16) NOT NULL, outcome VARCHAR(16) NULL, descriptor TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS privileged_read_ledger_receipt ON privileged_read_ledger (receipt_id, id)');
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS privileged_read_ledger_once ON privileged_read_ledger (receipt_id, event_type)');

        $db->executeStatement("CREATE TABLE IF NOT EXISTS audit_retention_policy (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(128) NOT NULL DEFAULT '', kind_pattern VARCHAR(64) NOT NULL DEFAULT '*', older_than_seconds INTEGER NOT NULL DEFAULT 0, action VARCHAR(16) NOT NULL DEFAULT 'purge', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS audit_retention_policy_uuid ON audit_retention_policy (uuid)');

        $db->executeStatement("CREATE TABLE IF NOT EXISTS audit_checkpoint (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(128) NOT NULL DEFAULT '', segment_start_id INTEGER NOT NULL DEFAULT 0, segment_end_id INTEGER NOT NULL DEFAULT 0, row_count INTEGER NOT NULL DEFAULT 0, segment_hash CHAR(64) NOT NULL DEFAULT '', prev_checkpoint_hash CHAR(64) NOT NULL DEFAULT '', checkpoint_hash CHAR(64) NOT NULL DEFAULT '', signature TEXT NOT NULL DEFAULT '', hash_version VARCHAR(16) NOT NULL DEFAULT 'v1', is_genesis INTEGER NOT NULL DEFAULT 0, pruned INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        if (!$schema->hasColumn('audit_checkpoint', 'pruned')) {
            $db->executeStatement('ALTER TABLE audit_checkpoint ADD COLUMN pruned INTEGER NOT NULL DEFAULT 0');
        }
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS audit_checkpoint_uuid ON audit_checkpoint (uuid)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS audit_checkpoint_segment_end ON audit_checkpoint (segment_end_id)');

        if ((int) $db->fetchOne('SELECT COUNT(*) FROM audit_checkpoint') === 0) {
            $end = (int) $db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_event');
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM audit_event');
            $hash = AuditCheckpointHasher::checkpointHash(1, $end, $count, AuditEventCanonicalizer::GENESIS_HASH, AuditEventCanonicalizer::GENESIS_HASH);
            $db->insert('audit_checkpoint', [
                'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 'segment_start_id' => 1,
                'segment_end_id' => $end, 'row_count' => $count, 'segment_hash' => AuditEventCanonicalizer::GENESIS_HASH,
                'prev_checkpoint_hash' => AuditEventCanonicalizer::GENESIS_HASH, 'checkpoint_hash' => $hash,
                'signature' => '', 'hash_version' => AuditEventCanonicalizer::HASH_VERSION, 'is_genesis' => 1,
                'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(SchemaBuilder $schema): void {}
};
