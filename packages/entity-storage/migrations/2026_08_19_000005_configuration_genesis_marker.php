<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Record the one-time genesis activation explicitly (#2428).
 *
 * A site that has never been installed has no configuration generation, and
 * every verified path to creating one needs a generation to already exist.
 * Genesis closes that loop by activating the canonical EMPTY generation.
 *
 * Genesis is truthfully an activation, so `operation` stays 'activate' — the
 * existing CHECK is not widened and no verb is added. The audit fact that is
 * genuinely missing is narrower: that a given activation was the one-time
 * empty genesis. That is recorded in an additive marker rather than left
 * implicit in a deterministic generation id, so an auditor reads it directly.
 *
 * Additive by design. The two ledger tables are NOT rebuilt and none of the
 * eight security triggers from 2026_08_15_000004 are dropped or recreated:
 * rebuilding a triggered, foreign-keyed activation ledger to add one boolean
 * would put far more at risk than the fact being recorded. Existing rows
 * default to 0, which is correct — no prior activation was genesis.
 *
 * The cross-column invariant (a genesis row must be an activation, and the
 * first one) is enforced by a new narrowly scoped refusal trigger rather than
 * by altering any existing trigger.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();

        foreach (['waaseyaa_config_candidate', 'waaseyaa_config_activation_v2'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $columns = array_map(
                static fn(array $column): string => (string) $column['name'],
                $connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)),
            );
            if (in_array('is_genesis', $columns, true)) {
                continue;
            }
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD COLUMN is_genesis INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis IN (0, 1))',
                $table,
            ));
        }

        // Genesis is the FIRST activation and only ever an activation. Anything
        // else claiming the marker is refused at the database, independently of
        // the application-layer request validation.
        if ($schema->hasTable('waaseyaa_config_activation_v2')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS waaseyaa_config_activation_genesis_guard
                BEFORE INSERT ON waaseyaa_config_activation_v2
                FOR EACH ROW WHEN NEW.is_genesis = 1 AND (NEW.operation <> 'activate' OR NEW.activation_sequence <> 1)
                BEGIN
                    SELECT RAISE(ABORT, 'Genesis activation must be the first activation and must be an activate operation.');
                END
                SQL);
        }

        if ($schema->hasTable('waaseyaa_config_candidate')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS waaseyaa_config_candidate_genesis_guard
                BEFORE INSERT ON waaseyaa_config_candidate
                FOR EACH ROW WHEN NEW.is_genesis = 1 AND (NEW.operation <> 'activate' OR NEW.expected_generation_id IS NOT NULL OR NEW.target_generation_id IS NOT NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'Genesis candidate must be an activate operation carrying no expected or target generation.');
                END
                SQL);
        }
    }
};
