<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Deterministic scheduler occurrence ledger. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('waaseyaa_scheduler_occurrences')) {
            return;
        }
        $schema->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_occurrences (
                occurrence_id VARCHAR(64) PRIMARY KEY,
                task_name VARCHAR(255) NOT NULL,
                schedule_generation VARCHAR(64) NOT NULL,
                due_at_ms INTEGER NOT NULL,
                trigger_key VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL,
                execution_fence INTEGER NOT NULL DEFAULT 0,
                failure_class VARCHAR(512) NULL,
                UNIQUE (task_name, schedule_generation, trigger_key)
            )
            SQL);
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only idempotency ledger.
    }
};
