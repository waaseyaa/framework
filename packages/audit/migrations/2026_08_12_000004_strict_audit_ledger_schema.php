<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $db = $schema->getConnection();
        $db->executeStatement('CREATE TABLE IF NOT EXISTS strict_audit_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id VARCHAR(64) NOT NULL, correlation_id VARCHAR(64) NOT NULL, event_type VARCHAR(32) NOT NULL, surface VARCHAR(64) NOT NULL, operation VARCHAR(191) NOT NULL, stage VARCHAR(64) NOT NULL, outcome VARCHAR(32) NOT NULL, actor_uid INTEGER DEFAULT NULL, descriptor TEXT DEFAULT NULL, created_at VARCHAR(32) NOT NULL)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS strict_audit_ledger_receipt ON strict_audit_ledger (receipt_id, id)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS strict_audit_ledger_correlation ON strict_audit_ledger (correlation_id)');
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS strict_audit_ledger_once ON strict_audit_ledger (receipt_id, event_type)');
    }
    public function down(SchemaBuilder $schema): void {}
};
