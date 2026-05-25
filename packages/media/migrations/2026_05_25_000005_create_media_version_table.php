<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Creates the `media_version` table for content-addressed blob versioning.
 *
 * Schema is authoritative in `kitty-specs/versioned-blob-media-abstraction-01KSEFTJ/`.
 *
 * Indexes:
 *   - `idx_media_version_media_uuid_vid`  — primary lookup (per-media versions, desc)
 *   - `idx_media_version_sha256`          — CAS deduplication check
 *   - `idx_media_version_created_at`      — time-ordered audit queries
 *
 * The migration is idempotent — IF NOT EXISTS guards allow kernel-boot replay.
 *
 * Refs DIR-005 (two-axis storage shape preserved; MediaVersion is an extension).
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('media_version')) {
            $conn->executeStatement(<<<'SQL'
                CREATE TABLE media_version (
                    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                    uuid VARCHAR(36) NOT NULL,
                    media_uuid VARCHAR(36) NOT NULL,
                    vid INTEGER NOT NULL,
                    blob_uri VARCHAR(512) NOT NULL,
                    mime VARCHAR(255) NOT NULL DEFAULT '',
                    size INTEGER NOT NULL DEFAULT 0,
                    sha256 VARCHAR(64) NOT NULL,
                    created_at INTEGER NOT NULL DEFAULT 0,
                    created_by INTEGER NOT NULL DEFAULT 0,
                    _data TEXT NOT NULL DEFAULT '{}'
                )
                SQL);

            $conn->executeStatement(
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_media_version_uuid '
                . 'ON media_version (uuid)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_media_version_media_uuid_vid '
                . 'ON media_version (media_uuid, vid DESC)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_media_version_sha256 '
                . 'ON media_version (sha256)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_media_version_created_at '
                . 'ON media_version (created_at)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('media_version');
    }
};
