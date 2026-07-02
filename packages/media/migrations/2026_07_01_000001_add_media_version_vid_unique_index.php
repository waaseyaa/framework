<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Adds the UNIQUE (media_uuid, vid) index to `media_version`.
 *
 * `MediaVersionRepository::nextVid()` allocates MAX(vid)+1 non-atomically;
 * this constraint is the collision detector `MediaVersionStorageDriver`'s
 * bounded retry relies on (pattern per #1706, revision-id allocation) —
 * without it a raced duplicate vid inserts silently and corrupts the
 * append-only version lineage.
 *
 * Additive and idempotent (IF NOT EXISTS). Shape-guarded: the generic
 * kernel-boot SqlSchemaHandler table (id, uuid, bundle, label, langcode,
 * _data — no media_uuid/vid columns) is skipped without error; the index
 * applies only to the migration-authored shape (see
 * 2026_05_25_000005_create_media_version_table.php). If duplicate
 * (media_uuid, vid) rows already exist, index creation fails LOUDLY —
 * an operator must resolve the duplicates; masking them would silently
 * bless corrupted history.
 *
 * Refs DIR-005.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('media_version')) {
            return;
        }

        if (!$schema->hasColumn('media_version', 'media_uuid') || !$schema->hasColumn('media_version', 'vid')) {
            // Generic kernel-boot table shape — nothing to index.
            return;
        }

        $schema->getConnection()->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_media_version_media_uuid_vid '
            . 'ON media_version (media_uuid, vid)',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive schema: no-op on down.
    }
};
