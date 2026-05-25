<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Creates the oidc_signing_key table for DB-backed key storage.
 *
 * Rotation policy: keep current (rotated_out_at IS NULL) + one previous
 * (most recent rotated_out_at IS NOT NULL). Older keys are pruned on rotate.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('oidc_signing_key')) {
            $conn->executeStatement(
                'CREATE TABLE oidc_signing_key (
                    kid VARCHAR(36) PRIMARY KEY NOT NULL,
                    algorithm VARCHAR(16) NOT NULL DEFAULT \'RS256\',
                    private_key_pem TEXT NOT NULL,
                    public_key_pem TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    rotated_out_at INTEGER
                )',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_signing_key_rotated ON oidc_signing_key (rotated_out_at)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: leave no-op.
    }
};
