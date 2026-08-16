<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Owns durable auth state formerly installed by request traffic. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();

        if (!$schema->hasTable('auth_tokens')) {
            $connection->executeStatement(
                'CREATE TABLE auth_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    user_id TEXT,
                    token_hash TEXT NOT NULL,
                    type TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    consumed_at INTEGER,
                    meta TEXT,
                    created_by TEXT
                )',
            );
        }

        if (!$schema->hasTable('auth_bearer_token')) {
            $connection->executeStatement(
                'CREATE TABLE auth_bearer_token (
                    id VARCHAR(20) PRIMARY KEY NOT NULL,
                    account_uid INTEGER NOT NULL,
                    audience VARCHAR(64) NOT NULL,
                    scopes TEXT NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    secret_hash VARCHAR(64) NOT NULL,
                    fingerprint VARCHAR(16) NOT NULL,
                    issued_at VARCHAR(26) NOT NULL,
                    expires_at VARCHAR(26) NOT NULL,
                    revoked_at VARCHAR(26),
                    rotated_from VARCHAR(20)
                )',
            );
        }
        $connection->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS auth_bearer_token_secret_hash ON auth_bearer_token (secret_hash)',
        );
        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS auth_bearer_token_account ON auth_bearer_token (account_uid)',
        );

        if (!$schema->hasTable('rate_limits')) {
            $connection->executeStatement(
                'CREATE TABLE rate_limits (
                    bucket_key TEXT PRIMARY KEY NOT NULL,
                    hits INTEGER NOT NULL,
                    reset_at INTEGER NOT NULL
                )',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authentication-state migration.
    }
};
