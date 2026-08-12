<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Universal aggregate mutation authority; serving code performs DML only. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('waaseyaa_entity_mutation_authority')) {
            return;
        }
        $schema->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_entity_mutation_authority (
                storage_authority VARCHAR(191) NOT NULL,
                tenant_id VARCHAR(191) NOT NULL,
                entity_type VARCHAR(191) NOT NULL,
                entity_id VARCHAR(191) NOT NULL,
                aggregate_version INTEGER NOT NULL CHECK (aggregate_version > 0),
                mutation_tag VARCHAR(64) NOT NULL CHECK (length(mutation_tag) = 64),
                lifecycle_state VARCHAR(16) NOT NULL CHECK (lifecycle_state IN ('active', 'tombstone')),
                PRIMARY KEY (storage_authority, tenant_id, entity_type, entity_id)
            )
            SQL);
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: removing tombstones would re-enable ABA.
    }
};
