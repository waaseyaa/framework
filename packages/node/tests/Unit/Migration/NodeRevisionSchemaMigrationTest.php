<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Node\NodeServiceProvider;

/**
 * CW-v1 WP-2 Task 2.2 — the node revision-schema migration retrofits the
 * `revision_id` / `published_revision_id` base-row pointer columns and the
 * `node_revision` table onto a `node` table that predates Task 2.1's
 * `revisionable: true` flip. Existing-deployment surface, so this must be
 * provably idempotent AND tolerant of a half-applied prior run (crash
 * between the pointer-column step and the node_revision-table step, in
 * either order).
 */
#[CoversNothing]
final class NodeRevisionSchemaMigrationTest extends TestCase
{
    private const string MIGRATION_FILE = '2026_07_06_000001_node_revision_schema.php';

    private const string LEGACY_NODE_TABLE_SQL = <<<'SQL'
        CREATE TABLE node (
            nid INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(128) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            type VARCHAR(128) NOT NULL DEFAULT '',
            langcode VARCHAR(12) NOT NULL DEFAULT 'en',
            _data TEXT NOT NULL DEFAULT '{}'
        )
        SQL;

    private function loadMigration(): Migration
    {
        $migrationPath = dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION_FILE;
        $this->assertFileExists($migrationPath, 'node revision schema migration must exist');

        $migration = require $migrationPath;
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(DBALDatabase $db, string $table): array
    {
        $columns = [];
        foreach ($db->getConnection()->createSchemaManager()->listTableColumns($table) as $column) {
            $columns[] = $column->getName();
        }
        sort($columns);

        return $columns;
    }

    private function tableExists(DBALDatabase $db, string $table): bool
    {
        return $db->getConnection()->createSchemaManager()->tablesExist([$table]);
    }

    #[Test]
    public function skips_when_the_node_table_does_not_exist(): void
    {
        $db = DBALDatabase::createSqlite();

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($db->getConnection()));

        $this->assertFalse($this->tableExists($db, 'node'));
    }

    #[Test]
    public function retrofits_pointer_columns_and_revision_table_on_a_legacy_node_table(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::LEGACY_NODE_TABLE_SQL);

        // Pre-existing content, as on a real deployment.
        $conn->executeStatement(
            "INSERT INTO node (uuid, title, type) VALUES ('u1', 'Hello', 'article')",
        );

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));

        $this->assertContains('revision_id', $this->columnsOf($db, 'node'));
        $this->assertContains('published_revision_id', $this->columnsOf($db, 'node'));
        $this->assertTrue($this->tableExists($db, 'node_revision'));

        // Pre-existing row survives untouched; new pointer columns read NULL.
        $row = null;
        foreach ($db->query('SELECT * FROM node WHERE nid = 1', []) as $r) {
            $row = $r;
        }
        $this->assertNotNull($row);
        $this->assertSame('Hello', $row['title']);
        $this->assertNull($row['revision_id']);
        $this->assertNull($row['published_revision_id']);
    }

    #[Test]
    public function is_idempotent_across_two_runs(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::LEGACY_NODE_TABLE_SQL);
        $conn->executeStatement(
            "INSERT INTO node (uuid, title, type) VALUES ('u1', 'Hello', 'article')",
        );

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));
        $columnsAfterFirstRun = $this->columnsOf($db, 'node');

        // Second run must not throw and must not alter the shape or the data.
        $migration->up(new SchemaBuilder($conn));

        $this->assertSame($columnsAfterFirstRun, $this->columnsOf($db, 'node'));

        $rowCount = 0;
        foreach ($db->query('SELECT nid FROM node', []) as $_) {
            ++$rowCount;
        }
        $this->assertSame(1, $rowCount, 'second run must not duplicate or alter existing rows');

        $revisionRowCount = 0;
        foreach ($db->query('SELECT entity_id FROM node_revision', []) as $_) {
            ++$revisionRowCount;
        }
        $this->assertSame(0, $revisionRowCount, 'schema-only migration must not write revision rows');
    }

    #[Test]
    public function completes_a_half_applied_run_missing_only_the_revision_table(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::LEGACY_NODE_TABLE_SQL);
        // Simulate a prior run that added the pointer columns but crashed
        // before creating node_revision.
        $conn->executeStatement('ALTER TABLE node ADD COLUMN revision_id INTEGER');
        $conn->executeStatement('ALTER TABLE node ADD COLUMN published_revision_id INTEGER');

        $this->assertFalse($this->tableExists($db, 'node_revision'));

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));

        $this->assertTrue($this->tableExists($db, 'node_revision'));
        // Re-adding an existing column must not have thrown (test reaching
        // here proves it); columns are exactly the pre-seeded pair, not
        // duplicated.
        $columns = $this->columnsOf($db, 'node');
        $this->assertSame(1, count(array_filter($columns, static fn(string $c): bool => $c === 'revision_id')));
    }

    #[Test]
    public function completes_a_half_applied_run_missing_only_the_pointer_columns(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::LEGACY_NODE_TABLE_SQL);
        // Simulate a prior run (or an operator hand-creating the table) that
        // has node_revision but never retrofitted the base-row pointers.
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE node_revision (
                entity_id VARCHAR(128) NOT NULL,
                revision_id INTEGER NOT NULL,
                revision_created VARCHAR(32) NOT NULL,
                revision_log TEXT,
                revision_author INTEGER,
                title VARCHAR(255) NOT NULL DEFAULT '',
                type VARCHAR(128) NOT NULL DEFAULT '',
                langcode VARCHAR(12) NOT NULL DEFAULT 'en',
                uuid VARCHAR(128) NOT NULL DEFAULT '',
                _data TEXT NOT NULL DEFAULT '{}',
                PRIMARY KEY (entity_id, revision_id)
            )
            SQL);

        $this->assertNotContains('revision_id', $this->columnsOf($db, 'node'));

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));

        $this->assertContains('revision_id', $this->columnsOf($db, 'node'));
        $this->assertContains('published_revision_id', $this->columnsOf($db, 'node'));
        $this->assertTrue($this->tableExists($db, 'node_revision'));
    }

    #[Test]
    public function is_a_no_op_against_a_table_already_shaped_by_the_fresh_install_path(): void
    {
        $db = DBALDatabase::createSqlite();

        $provider = new NodeServiceProvider();
        $provider->register();
        $nodeEntityType = $provider->getEntityTypes()[0];

        $handler = new SqlSchemaHandler($nodeEntityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $columnsBefore = $this->columnsOf($db, 'node');
        $revisionColumnsBefore = $this->columnsOf($db, 'node_revision');

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($db->getConnection()));

        $this->assertSame($columnsBefore, $this->columnsOf($db, 'node'));
        $this->assertSame($revisionColumnsBefore, $this->columnsOf($db, 'node_revision'));
    }

    #[Test]
    public function down_is_a_documented_no_op(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(self::LEGACY_NODE_TABLE_SQL);

        $migration = $this->loadMigration();
        $migration->up(new SchemaBuilder($conn));
        $migration->down(new SchemaBuilder($conn));

        // Additive schema is never rolled back destructively.
        $this->assertContains('revision_id', $this->columnsOf($db, 'node'));
        $this->assertTrue($this->tableExists($db, 'node_revision'));
    }
}
