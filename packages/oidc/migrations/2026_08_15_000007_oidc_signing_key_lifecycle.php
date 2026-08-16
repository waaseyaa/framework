<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Additive DB-02 authority for staged OIDC signing-key lifecycle state. */
return new class extends Migration {
    private const CONSERVATIVE_RETENTION_SECONDS = 7_863_000;

    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('oidc_signing_key')) {
            throw new \RuntimeException(
                'OIDC signing-key lifecycle migration requires the base signing-key schema.',
            );
        }

        $connection = $schema->getConnection();
        $activeRows = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM oidc_signing_key WHERE rotated_out_at IS NULL',
        );
        if ($activeRows > 1) {
            throw new \RuntimeException(
                'OIDC signing-key lifecycle migration refuses multiple legacy active keys; reconcile custody first.',
            );
        }

        $columns = [
            'key_version' => 'INTEGER',
            'state' => 'VARCHAR(32)',
            'staged_at' => 'INTEGER',
            'activated_at' => 'INTEGER',
            'retain_until' => 'INTEGER',
            'propagation_observed_at' => 'INTEGER',
            'propagation_evidence_hash' => 'CHAR(64)',
            'revoked_at' => 'INTEGER',
            'revocation_reason' => 'TEXT',
        ];
        foreach ($columns as $column => $definition) {
            if (!$schema->hasColumn('oidc_signing_key', $column)) {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE oidc_signing_key ADD COLUMN %s %s',
                    $column,
                    $definition,
                ));
            }
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT kid, created_at, rotated_out_at, key_version, state, staged_at,
                    activated_at, retain_until
             FROM oidc_signing_key ORDER BY created_at ASC, kid ASC',
        );
        $version = 1;
        foreach ($rows as $row) {
            $assignedVersion = $row['key_version'] === null ? $version : (int) $row['key_version'];
            $rotatedAt = $row['rotated_out_at'] === null ? null : (int) $row['rotated_out_at'];
            $state = is_string($row['state']) && $row['state'] !== ''
                ? $row['state']
                : ($rotatedAt === null ? 'active-sign-and-verify' : 'retired-verify-only');
            $connection->update('oidc_signing_key', [
                'key_version' => $assignedVersion,
                'state' => $state,
                'staged_at' => $row['staged_at'] ?? (int) $row['created_at'],
                'activated_at' => $row['activated_at']
                    ?? ($state === 'staged-verify-only' ? null : (int) $row['created_at']),
                'retain_until' => $row['retain_until']
                    ?? ($rotatedAt === null ? null : $rotatedAt + self::CONSERVATIVE_RETENTION_SECONDS),
            ], ['kid' => (string) $row['kid']]);
            $version = max($version, $assignedVersion + 1);
        }

        if (!$schema->hasTable('oidc_signing_key_version_sequence')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE oidc_signing_key_version_sequence (
                    singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1),
                    next_version INTEGER NOT NULL CHECK (next_version > 0)
                )
                SQL);
            $connection->insert('oidc_signing_key_version_sequence', [
                'singleton_id' => 1,
                'next_version' => $version,
            ]);
        } else {
            $connection->executeStatement(
                'UPDATE oidc_signing_key_version_sequence SET next_version = MAX(next_version, ?)
                 WHERE singleton_id = 1',
                [$version],
            );
        }

        if (!$schema->hasTable('oidc_signing_key_revocation')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE oidc_signing_key_revocation (
                    request_id VARCHAR(64) PRIMARY KEY NOT NULL,
                    kid VARCHAR(36) NOT NULL,
                    key_version INTEGER NOT NULL,
                    revoked_at INTEGER NOT NULL,
                    actor VARCHAR(255) NOT NULL,
                    reason TEXT NOT NULL,
                    stateless_window_from INTEGER NOT NULL,
                    stateless_window_to INTEGER NOT NULL,
                    persisted_access_affected INTEGER NOT NULL,
                    persisted_access_jti_hash CHAR(64) NOT NULL,
                    persisted_refresh_affected INTEGER NOT NULL,
                    persisted_refresh_jti_hash CHAR(64) NOT NULL
                )
                SQL);
            $connection->executeStatement(
                'CREATE INDEX idx_oidc_signing_key_revocation_kid
                 ON oidc_signing_key_revocation (kid, revoked_at)',
            );
        }

        $connection->executeStatement(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_oidc_signing_key_one_active
             ON oidc_signing_key (state) WHERE state = 'active-sign-and-verify'",
        );
        $connection->executeStatement(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_oidc_signing_key_one_staged
             ON oidc_signing_key (state) WHERE state = 'staged-verify-only'",
        );
        $connection->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_oidc_signing_key_version
             ON oidc_signing_key (key_version)',
        );
        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_oidc_signing_key_publishable
             ON oidc_signing_key (state, retain_until)',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only custody authority: version and revocation evidence survive upgrades.
    }
};
