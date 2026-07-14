<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Creates the `agent_run` aggregate and its `agent_audit_log` child table.
 *
 * Schema is authoritative in `kitty-specs/agent-executor-01KRWPK7/data-model.md`
 * §§ AgentRun, AgentAuditLog.
 *
 * Indexes (load-bearing):
 *   - `idx_agent_run_status_queued_at`   — reaper + queue inspection.
 *   - `idx_agent_run_account_queued_at`  — user history.
 *   - `idx_agent_run_status_started_at`  — reaper stuck-running scan.
 *   - `idx_agent_audit_run_occurred_at`  — audit replay by run.
 *
 * The migration is idempotent — `IF NOT EXISTS` guards on tables and indexes
 * allow kernel-boot replay tolerance.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('agent_run')) {
            $conn->executeStatement(<<<'SQL'
                CREATE TABLE agent_run (
                    id VARCHAR(36) NOT NULL,
                    account_id BIGINT NOT NULL,
                    agent_definition_id VARCHAR(255) DEFAULT NULL,
                    bundle_json TEXT NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'queued',
                    destructive_approval VARCHAR(16) NOT NULL DEFAULT 'none',
                    pending_approval_call_id VARCHAR(64) DEFAULT NULL,
                    approval_expires_at VARCHAR(35) DEFAULT NULL,
                    prompt TEXT NOT NULL,
                    response TEXT DEFAULT NULL,
                    transcript_json TEXT NOT NULL DEFAULT '[]',
                    token_usage_in INTEGER NOT NULL DEFAULT 0,
                    token_usage_out INTEGER NOT NULL DEFAULT 0,
                    cost_cents INTEGER DEFAULT NULL,
                    tool_call_count INTEGER NOT NULL DEFAULT 0,
                    queued_at VARCHAR(35) NOT NULL,
                    started_at VARCHAR(35) DEFAULT NULL,
                    finished_at VARCHAR(35) DEFAULT NULL,
                    error_code VARCHAR(64) DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    _data TEXT NOT NULL DEFAULT '{}',
                    PRIMARY KEY (id)
                )
                SQL);

            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_agent_run_status_queued_at '
                . 'ON agent_run (status, queued_at)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_agent_run_account_queued_at '
                . 'ON agent_run (account_id, queued_at)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_agent_run_status_started_at '
                . 'ON agent_run (status, started_at)',
            );
        }

        if (!$schema->hasTable('agent_audit_log')) {
            $conn->executeStatement(<<<'SQL'
                CREATE TABLE agent_audit_log (
                    id VARCHAR(36) NOT NULL,
                    run_id VARCHAR(36) NOT NULL,
                    iteration INTEGER NOT NULL,
                    event_type VARCHAR(32) NOT NULL,
                    tool_name VARCHAR(255) DEFAULT NULL,
                    tool_arguments_json TEXT DEFAULT NULL,
                    tool_result_summary TEXT DEFAULT NULL,
                    success INTEGER NOT NULL DEFAULT 1,
                    duration_ms INTEGER DEFAULT NULL,
                    occurred_at VARCHAR(35) NOT NULL,
                    _data TEXT NOT NULL DEFAULT '{}',
                    PRIMARY KEY (id)
                )
                SQL);

            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_agent_audit_run_occurred_at '
                . 'ON agent_audit_log (run_id, occurred_at)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('agent_audit_log');
        $schema->dropIfExists('agent_run');
    }
};
