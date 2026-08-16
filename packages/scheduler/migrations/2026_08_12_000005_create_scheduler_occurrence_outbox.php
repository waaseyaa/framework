<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Transactional handoff from scheduler occurrence recording to persistent queue dispatch. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('waaseyaa_scheduler_occurrence_outbox')) {
            return;
        }
        $schema->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_occurrence_outbox (
                occurrence_id VARCHAR(64) PRIMARY KEY,
                message_class VARCHAR(512) NOT NULL,
                lease_ttl_ms INTEGER NOT NULL,
                state VARCHAR(32) NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error_class VARCHAR(512) NULL
            )
            SQL);
        $schema->getConnection()->executeStatement(
            'CREATE INDEX waaseyaa_scheduler_occurrence_outbox_state_idx '
            . 'ON waaseyaa_scheduler_occurrence_outbox (state, occurrence_id)',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only occurrence delivery history.
    }
};
