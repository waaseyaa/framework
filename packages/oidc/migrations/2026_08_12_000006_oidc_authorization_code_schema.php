<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Owns the authorization-code schema formerly installed by request traffic. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();

        if (!$schema->hasTable('oidc_authorization_codes')) {
            $connection->executeStatement(
                'CREATE TABLE oidc_authorization_codes (
                    code VARCHAR(128) PRIMARY KEY,
                    client_id VARCHAR(255) NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    redirect_uri TEXT NOT NULL,
                    scopes TEXT NOT NULL,
                    code_challenge VARCHAR(128) NOT NULL,
                    code_challenge_method VARCHAR(16) NOT NULL,
                    issued_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    consumed_at INTEGER,
                    nonce VARCHAR(255)
                )',
            );
        } elseif (!$schema->hasColumn('oidc_authorization_codes', 'nonce')) {
            $connection->executeStatement(
                'ALTER TABLE oidc_authorization_codes ADD COLUMN nonce VARCHAR(255)',
            );
        }

        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_oidc_auth_codes_expires_at ON oidc_authorization_codes (expires_at)',
        );
        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_oidc_auth_codes_client_id ON oidc_authorization_codes (client_id)',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authorization state migration.
    }
};
