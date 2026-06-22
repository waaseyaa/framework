<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Schema;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Manages the attachment table schema.
 *
 * Creates the full attachment table in one call, including all base columns
 * (matching what SqlSchemaHandler would auto-generate for this entity type)
 * and the attachment-specific columns and composite indexes.
 *
 * Columns:
 *   - id               INTEGER PK AUTOINCREMENT
 *   - uuid             VARCHAR(128) UNIQUE NOT NULL
 *   - bundle           VARCHAR(128) NOT NULL DEFAULT ''
 *   - filename         VARCHAR(255) NOT NULL DEFAULT '' (label key)
 *   - langcode         VARCHAR(12) NOT NULL DEFAULT 'en'
 *   - parent_entity_type VARCHAR(64) NOT NULL DEFAULT ''
 *   - parent_entity_id   VARCHAR(255) NOT NULL DEFAULT ''
 *   - is_active          INTEGER NOT NULL DEFAULT 0
 *   - created_at         INTEGER NOT NULL DEFAULT 0
 *   - updated_at         INTEGER NOT NULL DEFAULT 0
 *   - _data              TEXT NOT NULL DEFAULT '{}' (JSON blob: filename, content_type, size, storage_uri, checksum)
 *
 * Indexes:
 *   - UNIQUE on uuid
 *   - Composite on (parent_entity_type, parent_entity_id)
 *   - Composite on (parent_entity_type, parent_entity_id, is_active) — fast active lookup
 *
 * @api
 */
final class AttachmentSchema
{
    private const TABLE = 'attachment';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * Ensures the attachment table exists with all required columns and indexes.
     *
     * Idempotent: no-op if the table already exists.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        $schema->createTable(self::TABLE, [
            'fields' => [
                'id' => [
                    'type' => 'serial',
                    'not null' => true,
                ],
                'uuid' => [
                    'type' => 'varchar',
                    'length' => 128,
                    'not null' => true,
                    'default' => '',
                ],
                'bundle' => [
                    'type' => 'varchar',
                    'length' => 128,
                    'not null' => true,
                    'default' => '',
                ],
                'filename' => [
                    'type' => 'varchar',
                    'length' => 255,
                    'not null' => true,
                    'default' => '',
                ],
                'langcode' => [
                    'type' => 'varchar',
                    'length' => 12,
                    'not null' => true,
                    'default' => 'en',
                ],
                'parent_entity_type' => [
                    'type' => 'varchar',
                    'length' => 64,
                    'not null' => true,
                    'default' => '',
                ],
                'parent_entity_id' => [
                    'type' => 'varchar',
                    'length' => 255,
                    'not null' => true,
                    'default' => '',
                ],
                'is_active' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                'created_at' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                'updated_at' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                '_data' => [
                    'type' => 'text',
                    'not null' => true,
                    'default' => '{}',
                ],
            ],
            'primary key' => ['id'],
            'unique keys' => [
                self::TABLE . '_uuid' => ['uuid'],
            ],
            'indexes' => [
                self::TABLE . '_bundle' => ['bundle'],
                self::TABLE . '_parent' => ['parent_entity_type', 'parent_entity_id'],
                self::TABLE . '_parent_active' => ['parent_entity_type', 'parent_entity_id', 'is_active'],
            ],
        ]);
    }
}
