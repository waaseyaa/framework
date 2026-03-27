<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Relationship\RelationshipSchemaManager;

#[CoversClass(RelationshipSchemaManager::class)]
final class RelationshipSchemaManagerTest extends TestCase
{
    #[Test]
    public function ensure_does_nothing_when_table_does_not_exist(): void
    {
        $database = DBALDatabase::createSqlite();
        $manager = new RelationshipSchemaManager($database);

        // Should not throw — early return when table doesn't exist
        $this->expectNotToPerformAssertions();
        $manager->ensure();
    }

    #[Test]
    public function ensure_adds_missing_columns_to_existing_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();

        $columns = $this->getColumnNames($database);
        $expectedColumns = [
            'from_entity_type', 'from_entity_id',
            'to_entity_type', 'to_entity_id',
            'directionality', 'status', 'weight',
            'start_date', 'end_date', 'confidence',
            'source_ref', 'notes',
        ];
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columns, "Column '$col' should exist after ensure()");
        }
    }

    #[Test]
    public function ensure_creates_all_four_indexes(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL,
  from_entity_type TEXT NOT NULL DEFAULT '',
  from_entity_id TEXT NOT NULL DEFAULT '',
  to_entity_type TEXT NOT NULL DEFAULT '',
  to_entity_id TEXT NOT NULL DEFAULT '',
  directionality TEXT NOT NULL DEFAULT 'directed',
  status INTEGER NOT NULL DEFAULT 1,
  weight REAL,
  start_date INTEGER,
  end_date INTEGER,
  confidence REAL,
  source_ref TEXT,
  notes TEXT
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();

        $indexes = $this->getIndexNames($database);
        $this->assertContains('relationship_from_status_idx', $indexes);
        $this->assertContains('relationship_to_status_idx', $indexes);
        $this->assertContains('relationship_type_status_idx', $indexes);
        $this->assertContains('relationship_temporal_idx', $indexes);
    }

    #[Test]
    public function ensure_is_idempotent(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();
        $manager->ensure();

        $indexes = $this->getIndexNames($database);
        $uniqueIndexes = array_unique($indexes);
        $this->assertCount(count($uniqueIndexes), $indexes, 'No duplicate indexes after double ensure()');
    }

    /** @return list<string> */
    private function getColumnNames(DBALDatabase $database): array
    {
        $rows = $database->query("PRAGMA table_info('relationship')");
        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $row['name'];
        }
        return $columns;
    }

    /** @return list<string> */
    private function getIndexNames(DBALDatabase $database): array
    {
        $rows = $database->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'relationship'");
        $names = [];
        foreach ($rows as $row) {
            $names[] = $row['name'];
        }
        return $names;
    }
}
