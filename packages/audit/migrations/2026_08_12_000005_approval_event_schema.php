<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $db = $schema->getConnection();
        $db->executeStatement('CREATE TABLE IF NOT EXISTS mcp_approval_event (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id VARCHAR(64) NOT NULL, event_type VARCHAR(32) NOT NULL, request_key VARCHAR(64) NOT NULL, principal_key VARCHAR(191) NOT NULL, surface VARCHAR(64) NOT NULL, operation VARCHAR(191) NOT NULL, arguments_fingerprint VARCHAR(64) NOT NULL, correlation_id VARCHAR(64) NOT NULL, safe_arguments TEXT DEFAULT NULL, expires_at VARCHAR(32) DEFAULT NULL, decision VARCHAR(16) DEFAULT NULL, operator_uid INTEGER DEFAULT NULL, decision_reason VARCHAR(500) DEFAULT NULL, receipt_id VARCHAR(64) DEFAULT NULL, created_at VARCHAR(32) NOT NULL)');
        $db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS mcp_approval_event_once ON mcp_approval_event (request_id, event_type)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS mcp_approval_event_request_key ON mcp_approval_event (request_key, id)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS mcp_approval_event_correlation ON mcp_approval_event (correlation_id)');
    }
    public function down(SchemaBuilder $schema): void {}
};
