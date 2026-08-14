<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Versioned configuration generations and their single active pointer. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        if (!$schema->hasTable('waaseyaa_config_generation')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_generation (
                    authority_id VARCHAR(64) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                    schema_version VARCHAR(64) NOT NULL,
                    manifest_hash VARCHAR(64) NOT NULL,
                    lifecycle_state VARCHAR(16) NOT NULL CHECK (lifecycle_state IN ('staged', 'active', 'superseded')),
                    created_at VARCHAR(40) NOT NULL,
                    PRIMARY KEY (authority_id, generation_id),
                    UNIQUE (authority_id, activation_sequence)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_entry')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_entry (
                    authority_id VARCHAR(64) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    config_name VARCHAR(255) NOT NULL,
                    entity_type VARCHAR(191) NOT NULL,
                    entity_id VARCHAR(191) NOT NULL,
                    uuid VARCHAR(64) NOT NULL,
                    dependencies_json TEXT NOT NULL,
                    langcode VARCHAR(32) NOT NULL,
                    fields_json TEXT NOT NULL,
                    content_hash VARCHAR(64) NOT NULL,
                    PRIMARY KEY (authority_id, generation_id, config_name),
                    UNIQUE (authority_id, generation_id, entity_type, entity_id),
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation (authority_id, generation_id)
                        ON DELETE CASCADE
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_activation')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_activation (
                    authority_id VARCHAR(64) NOT NULL PRIMARY KEY,
                    generation_id VARCHAR(64) NOT NULL,
                    activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                    activated_at VARCHAR(40) NOT NULL,
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation (authority_id, generation_id)
                )
                SQL);
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: activation evidence must not disappear.
    }
};
