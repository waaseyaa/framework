<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Creates oidc_access_token and oidc_refresh_token tables.
 *
 * - oidc_access_token: opaque bearer token with jti (UUID), revoked_at for revocation.
 * - oidc_refresh_token: refresh token chain with chain_root_jti for theft-detection cascade.
 *
 * Idempotent: uses CREATE TABLE IF NOT EXISTS + addColumnIfMissing.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('oidc_access_token')) {
            $conn->executeStatement(
                'CREATE TABLE oidc_access_token (
                    jti VARCHAR(128) PRIMARY KEY NOT NULL,
                    token VARCHAR(128) NOT NULL UNIQUE,
                    client_id VARCHAR(255) NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    scope TEXT NOT NULL,
                    issued_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER
                )',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_access_token_account ON oidc_access_token (account_id)',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_access_token_expires ON oidc_access_token (expires_at)',
            );
        }

        if (!$schema->hasTable('oidc_refresh_token')) {
            $conn->executeStatement(
                'CREATE TABLE oidc_refresh_token (
                    jti VARCHAR(128) PRIMARY KEY NOT NULL,
                    token VARCHAR(128) NOT NULL UNIQUE,
                    access_token_jti VARCHAR(128) NOT NULL,
                    client_id VARCHAR(255) NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    scope TEXT NOT NULL,
                    auth_time INTEGER NOT NULL,
                    chain_root_jti VARCHAR(128) NOT NULL,
                    issued_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER
                )',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_refresh_token_account ON oidc_refresh_token (account_id)',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_refresh_token_chain ON oidc_refresh_token (chain_root_jti)',
            );
            $conn->executeStatement(
                'CREATE INDEX idx_oidc_refresh_token_access ON oidc_refresh_token (access_token_jti)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping tables is irreversible in production; leave no-op.
    }
};
