<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Migration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * The additive vid-unique-index migration must be idempotent AND tolerant of
 * both media_version table shapes: the migration-authored shape (real typed
 * columns) gets the unique index; the generic kernel-boot SqlSchemaHandler
 * shape (id, uuid, bundle, label, langcode, _data — no vid column) is
 * skipped without error.
 */
#[CoversNothing]
final class MediaVersionVidUniqueIndexMigrationTest extends TestCase
{
    private const string MIGRATION_FILE = '2026_07_01_000001_add_media_version_vid_unique_index.php';

    private const string MIGRATION_SHAPED_TABLE_SQL = <<<'SQL'
        CREATE TABLE media_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL DEFAULT '',
            media_uuid VARCHAR(36) NOT NULL DEFAULT '',
            vid INTEGER NOT NULL DEFAULT 0,
            blob_uri VARCHAR(512) NOT NULL DEFAULT '',
            mime VARCHAR(255) NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            sha256 VARCHAR(64) NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            _data TEXT NOT NULL DEFAULT '{}'
        )
        SQL;

    private const string BLOB_SHAPED_TABLE_SQL = <<<'SQL'
        CREATE TABLE media_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL DEFAULT '',
            bundle VARCHAR(128) NOT NULL DEFAULT '',
            label VARCHAR(255) NOT NULL DEFAULT '',
            langcode VARCHAR(12) NOT NULL DEFAULT 'en',
            _data TEXT NOT NULL DEFAULT '{}'
        )
        SQL;

    private function loadMigration(): Migration
    {
        $migrationPath = dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION_FILE;
        $this->assertFileExists($migrationPath, 'vid unique-index migration must exist');

        $migration = require $migrationPath;
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    #[Test]
    public function adds_unique_index_on_the_migration_shaped_table_and_is_idempotent(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::MIGRATION_SHAPED_TABLE_SQL);

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));
        // Idempotent: a second run must not throw.
        $migration->up(new SchemaBuilder($conn));

        $db->query(
            "INSERT INTO media_version (uuid, media_uuid, vid, sha256) VALUES ('v1', 'media-a', 1, 'x')",
            [],
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $db->query(
            "INSERT INTO media_version (uuid, media_uuid, vid, sha256) VALUES ('v2', 'media-a', 1, 'y')",
            [],
        );
    }

    #[Test]
    public function skips_the_blob_shaped_kernel_boot_table_without_error(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::BLOB_SHAPED_TABLE_SQL);

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));

        // No vid column → no index, and duplicate uuids in _data-land are not
        // this migration's business. The assertion is simply "no throw" plus
        // the table remains writable.
        $db->query("INSERT INTO media_version (uuid, bundle) VALUES ('a', 'b')", []);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function skips_when_the_table_does_not_exist(): void
    {
        $db = DBALDatabase::createSqlite();

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($db->getConnection()));
        $this->addToAssertionCount(1);
    }
}
