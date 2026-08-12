<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Durable sink-side scheduler fence authority. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('waaseyaa_scheduler_effect_fences')) {
            return;
        }
        $schema->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_effect_fences (
                resource_key VARCHAR(512) NOT NULL,
                fence_domain VARCHAR(255) NOT NULL,
                accepted_fence INTEGER NOT NULL CHECK (accepted_fence > 0),
                effect_id VARCHAR(255) NOT NULL,
                PRIMARY KEY (resource_key, fence_domain)
            )
            SQL);
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: accepted fences prevent stale-owner replay.
    }
};
