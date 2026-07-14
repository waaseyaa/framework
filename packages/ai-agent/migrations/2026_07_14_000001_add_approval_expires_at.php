<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Adds the persisted HITL deadline used by the abandoned-run reaper. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('agent_run') || $schema->hasColumn('agent_run', 'approval_expires_at')) {
            return;
        }

        $schema->getConnection()->executeStatement(
            'ALTER TABLE agent_run ADD COLUMN approval_expires_at VARCHAR(35) DEFAULT NULL',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive compatibility column: rollback is intentionally non-destructive.
    }
};
