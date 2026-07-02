<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

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
 *   - Partial UNIQUE on (parent_entity_type, parent_entity_id) WHERE is_active = 1,
 *     on platforms that support partial indexes (SQLite, PostgreSQL) — see
 *     {@see ensureActivePartialUniqueIndex()}. This is a backstop, not the
 *     primary invariant mechanism; it is a no-op (with a logged warning) on
 *     MySQL/MariaDB, which have no partial-index support at all.
 *
 * @api
 */
final class AttachmentSchema
{
    private const TABLE = 'attachment';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Ensures the attachment table exists with all required columns and indexes.
     *
     * Idempotent: no-op if the table already exists.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if (!$schema->tableExists(self::TABLE)) {
            $this->createTable($schema);
        }

        // Idempotent (CREATE ... IF NOT EXISTS) regardless of whether the
        // table already existed — re-running against a pre-existing install
        // materializes the backstop index without touching table creation.
        $this->ensureActivePartialUniqueIndex();
    }

    private function createTable(SchemaInterface $schema): void
    {
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

    /**
     * Belt-and-suspenders backstop for the at-most-one-active invariant:
     *
     *   CREATE UNIQUE INDEX <table>_one_active_per_parent
     *       ON <table>(parent_entity_type, parent_entity_id) WHERE is_active = 1
     *
     * This is NOT the primary enforcement mechanism — that is the save-path
     * guard ({@see \Waaseyaa\Attachment\AttachmentRepository::save()} /
     * {@see \Waaseyaa\Attachment\AttachmentActiveGuardListener}), which is
     * the only mechanism that runs on every platform. This index is an
     * additional hard backstop available where the platform supports
     * partial indexes:
     *
     *   - SQLite (>=3.8) and PostgreSQL (>=9.0) support partial indexes
     *     natively — a second concurrent writer attempting to set
     *     `is_active = 1` for a parent that already has an active row fails
     *     the INSERT/UPDATE outright with a unique-constraint violation,
     *     which closes the residual cross-process race documented on
     *     {@see \Waaseyaa\Attachment\AttachmentActiveGuardListener} for
     *     those two platforms.
     *   - MySQL/MariaDB have no partial-index support at all. This method
     *     is a no-op (with a logged warning) on those platforms; the
     *     invariant there rests entirely on the save-path guard plus
     *     {@see \Waaseyaa\Attachment\AttachmentRepository::getActive()}'s
     *     detection.
     *
     * Best-effort: wrapped in try/catch (unlike
     * `SqlSchemaHandler::ensureSqlBlobTranslatablePartialUuidIndex()`, which
     * this mirrors) because this index is an optional hardening layer, not
     * load-bearing schema — a platform this code fails to recognize must
     * not block `ensureTable()`/install.
     */
    private function ensureActivePartialUniqueIndex(): void
    {
        $indexName = self::TABLE . '_one_active_per_parent';

        $quote = $this->database instanceof DBALDatabase
            ? fn(string $id): string => $this->database->quoteIdentifier($id)
            : static fn(string $id): string => '"' . str_replace('"', '""', $id) . '"';

        $platform = $this->detectDatabasePlatform();

        if ($platform === 'mysql' || $platform === 'mariadb') {
            $this->logger->warning(\sprintf(
                'AttachmentSchema: MySQL/MariaDB does not support partial unique indexes; '
                . 'the at-most-one-active invariant on "%s" relies solely on the save-path '
                . 'guard (AttachmentRepository::save() / AttachmentActiveGuardListener) and '
                . 'AttachmentRepository::getActive() detection on this platform.',
                self::TABLE,
            ));

            return;
        }

        $sql = \sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, %s) WHERE %s = 1',
            $quote($indexName),
            $quote(self::TABLE),
            $quote('parent_entity_type'),
            $quote('parent_entity_id'),
            $quote('is_active'),
        );

        try {
            $this->database->query($sql);
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf(
                'AttachmentSchema: failed to materialize the partial unique active-row index '
                . 'on "%s" (platform "%s"): %s. The at-most-one-active invariant falls back to '
                . 'the save-path guard and getActive() detection alone.',
                self::TABLE,
                $platform,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Best-effort platform discovery for partial-index emission.
     *
     * Returns 'sqlite', 'postgresql', 'mysql', 'mariadb', or 'unknown'.
     * When the database is not a DBALDatabase (e.g. a test stub), returns
     * 'sqlite' which yields the standards-compliant partial index syntax.
     */
    private function detectDatabasePlatform(): string
    {
        $db = $this->database;
        if (!$db instanceof DBALDatabase) {
            return 'sqlite';
        }
        try {
            $platform = $db->getConnection()->getDatabasePlatform();
            $platformClass = strtolower($platform::class);
        } catch (\Throwable) {
            return 'unknown';
        }
        if (str_contains($platformClass, 'sqlite')) {
            return 'sqlite';
        }
        if (str_contains($platformClass, 'postgres')) {
            return 'postgresql';
        }
        if (str_contains($platformClass, 'mariadb')) {
            return 'mariadb';
        }
        if (str_contains($platformClass, 'mysql')) {
            return 'mysql';
        }
        return 'unknown';
    }
}
