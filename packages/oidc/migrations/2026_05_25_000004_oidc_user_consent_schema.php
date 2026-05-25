<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Creates oidc_user_consent — records user consent per (account, client, scope_set).
 *
 * The scope_set_hash is SHA-256 of the sorted, space-joined scope list,
 * allowing exact-match consent checks without storing the full scope string
 * in the unique index.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('oidc_user_consent')) {
            $conn->executeStatement(
                'CREATE TABLE oidc_user_consent (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    client_id VARCHAR(255) NOT NULL,
                    scope_set_hash CHAR(64) NOT NULL,
                    granted_at INTEGER NOT NULL
                )',
            );
            $conn->executeStatement(
                'CREATE UNIQUE INDEX idx_oidc_user_consent_unique ON oidc_user_consent (account_id, client_id, scope_set_hash)',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_user_consent_account ON oidc_user_consent (account_id)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: leave no-op.
    }
};
