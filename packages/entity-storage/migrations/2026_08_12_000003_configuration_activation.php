<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Immutable configuration generations and append-only ordered activation. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        if (!$schema->hasTable('waaseyaa_config_activation_counter')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_activation_counter (
                    authority_id VARCHAR(64) NOT NULL PRIMARY KEY,
                    last_sequence INTEGER NOT NULL CHECK (last_sequence >= 0)
                )
                SQL);
            if ($schema->hasTable('waaseyaa_config_activation')) {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO waaseyaa_config_activation_counter (authority_id, last_sequence)
                    SELECT authority_id, MAX(activation_sequence)
                    FROM waaseyaa_config_activation
                    GROUP BY authority_id
                    SQL);
            }
        }
        if (!$schema->hasTable('waaseyaa_config_candidate_sweep_fence')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_candidate_sweep_fence (
                    authority_id VARCHAR(64) NOT NULL,
                    lease_domain VARCHAR(128) NOT NULL,
                    last_fence INTEGER NOT NULL CHECK (last_fence > 0),
                    PRIMARY KEY (authority_id, lease_domain)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_generation_v2')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_generation_v2 (
                    authority_id VARCHAR(64) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    schema_version VARCHAR(64) NOT NULL,
                    manifest_hash VARCHAR(64) NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    PRIMARY KEY (authority_id, generation_id)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_entry_v2')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_entry_v2 (
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
                        REFERENCES waaseyaa_config_generation_v2 (authority_id, generation_id)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_candidate')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_candidate (
                    authority_id VARCHAR(64) NOT NULL,
                    activation_request_id VARCHAR(128) NOT NULL,
                    input_hash VARCHAR(64) NOT NULL,
                    expected_generation_id VARCHAR(64) NULL,
                    expected_activation_sequence INTEGER NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    plan_hash VARCHAR(64) NOT NULL,
                    operation VARCHAR(16) NOT NULL CHECK (operation IN ('activate', 'rollback')),
                    target_generation_id VARCHAR(64) NULL,
                    lifecycle_state VARCHAR(16) NOT NULL CHECK (lifecycle_state IN ('staged', 'committed', 'rejected', 'superseded')),
                    created_at VARCHAR(40) NOT NULL,
                    committed_sequence INTEGER NULL,
                    PRIMARY KEY (authority_id, activation_request_id),
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation_v2 (authority_id, generation_id)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_activation_v2')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_activation_v2 (
                    authority_id VARCHAR(64) NOT NULL,
                    activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                    activation_request_id VARCHAR(128) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    previous_generation_id VARCHAR(64) NULL,
                    previous_activation_sequence INTEGER NULL,
                    plan_hash VARCHAR(64) NOT NULL,
                    operation VARCHAR(16) NOT NULL CHECK (operation IN ('activate', 'rollback')),
                    target_generation_id VARCHAR(64) NULL,
                    activated_at VARCHAR(40) NOT NULL,
                    PRIMARY KEY (authority_id, activation_sequence),
                    UNIQUE (authority_id, activation_request_id),
                    FOREIGN KEY (authority_id, activation_request_id)
                        REFERENCES waaseyaa_config_candidate (authority_id, activation_request_id),
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation_v2 (authority_id, generation_id)
                )
                SQL);
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: activation ordering and evidence never rewind.
    }
};
