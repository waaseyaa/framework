<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Transactional CFG-03 manifest provenance and monotonic replay state. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        if (!$schema->hasTable('waaseyaa_config_manifest_replay')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_manifest_replay (
                    authority_id VARCHAR(64) NOT NULL,
                    bundle_scope VARCHAR(191) NOT NULL,
                    trust_key_reference VARCHAR(255) NOT NULL,
                    last_sequence INTEGER NOT NULL CHECK (last_sequence > 0),
                    manifest_hash VARCHAR(71) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                    PRIMARY KEY (authority_id, bundle_scope, trust_key_reference),
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation_v2 (authority_id, generation_id)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_entry_contract')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_entry_contract (
                    authority_id VARCHAR(64) NOT NULL,
                    generation_id VARCHAR(64) NOT NULL,
                    config_name VARCHAR(255) NOT NULL,
                    format VARCHAR(64) NOT NULL,
                    schema_id VARCHAR(191) NOT NULL,
                    schema_version INTEGER NOT NULL CHECK (schema_version > 0),
                    schema_hash VARCHAR(71) NOT NULL,
                    owner_package VARCHAR(191) NOT NULL,
                    owner_config_contract_version INTEGER NOT NULL CHECK (owner_config_contract_version > 0),
                    effective_entry_hash VARCHAR(71) NOT NULL,
                    PRIMARY KEY (authority_id, generation_id, config_name),
                    FOREIGN KEY (authority_id, generation_id, config_name)
                        REFERENCES waaseyaa_config_entry_v2 (authority_id, generation_id, config_name)
                )
                SQL);
        }
        if (!$schema->hasTable('waaseyaa_config_activation_manifest')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_config_activation_manifest (
                    authority_id VARCHAR(64) NOT NULL,
                    activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                    generation_id VARCHAR(64) NOT NULL,
                    manifest_hash VARCHAR(71) NOT NULL,
                    manifest_bytes TEXT NOT NULL,
                    registry_checksum VARCHAR(71) NOT NULL,
                    bundle_scope VARCHAR(191) NOT NULL,
                    bundle_sequence INTEGER NOT NULL CHECK (bundle_sequence > 0),
                    trust_key_reference VARCHAR(255) NOT NULL,
                    signed INTEGER NOT NULL CHECK (signed IN (0, 1)),
                    PRIMARY KEY (authority_id, activation_sequence),
                    UNIQUE (authority_id, bundle_scope, trust_key_reference, bundle_sequence),
                    FOREIGN KEY (authority_id, generation_id)
                        REFERENCES waaseyaa_config_generation_v2 (authority_id, generation_id)
                )
                SQL);
        }
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_manifest_replay_activation_insert_guard
            BEFORE INSERT ON waaseyaa_config_manifest_replay
            FOR EACH ROW WHEN NOT EXISTS (
                SELECT 1 FROM waaseyaa_config_activation_v2
                WHERE authority_id = NEW.authority_id AND activation_sequence = NEW.activation_sequence
            )
            BEGIN
                SELECT RAISE(ABORT, 'configuration replay state requires a committed activation');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_manifest_replay_activation_update_guard
            BEFORE UPDATE OF authority_id, activation_sequence ON waaseyaa_config_manifest_replay
            FOR EACH ROW WHEN NOT EXISTS (
                SELECT 1 FROM waaseyaa_config_activation_v2
                WHERE authority_id = NEW.authority_id AND activation_sequence = NEW.activation_sequence
            )
            BEGIN
                SELECT RAISE(ABORT, 'configuration replay state requires a committed activation');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_manifest_replay_monotonic_guard
            BEFORE UPDATE OF last_sequence ON waaseyaa_config_manifest_replay
            FOR EACH ROW WHEN NEW.last_sequence <= OLD.last_sequence
            BEGIN
                SELECT RAISE(ABORT, 'configuration replay high-water must advance monotonically');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_activation_manifest_activation_guard
            BEFORE INSERT ON waaseyaa_config_activation_manifest
            FOR EACH ROW WHEN NOT EXISTS (
                SELECT 1 FROM waaseyaa_config_activation_v2
                WHERE authority_id = NEW.authority_id AND activation_sequence = NEW.activation_sequence
            )
            BEGIN
                SELECT RAISE(ABORT, 'configuration manifest evidence requires a committed activation');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_activation_manifest_update_guard
            BEFORE UPDATE ON waaseyaa_config_activation_manifest
            BEGIN
                SELECT RAISE(ABORT, 'configuration manifest provenance is append-only');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_activation_manifest_delete_guard
            BEFORE DELETE ON waaseyaa_config_activation_manifest
            BEGIN
                SELECT RAISE(ABORT, 'configuration manifest provenance is append-only');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_entry_contract_update_guard
            BEFORE UPDATE ON waaseyaa_config_entry_contract
            BEGIN
                SELECT RAISE(ABORT, 'configuration entry contract evidence is immutable');
            END
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS waaseyaa_config_entry_contract_delete_guard
            BEFORE DELETE ON waaseyaa_config_entry_contract
            BEGIN
                SELECT RAISE(ABORT, 'configuration entry contract evidence is immutable');
            END
            SQL);
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: verified manifest and replay evidence never rewind.
    }
};
