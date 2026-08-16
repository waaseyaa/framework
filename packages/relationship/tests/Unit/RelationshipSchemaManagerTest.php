<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(RelationshipSchemaManager::class)]
final class RelationshipSchemaManagerTest extends TestCase
{
    #[Test]
    public function ensure_refuses_a_missing_table_without_creating_it(): void
    {
        $database = DBALDatabase::createSqlite();
        $manager = new RelationshipSchemaManager($database);

        try {
            $manager->ensure();
            self::fail('Missing relationship schema must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
        }

        self::assertFalse($database->schema()->tableExists('relationship'));
    }

    #[Test]
    public function ensure_refuses_an_incomplete_table_without_repairing_it(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
            CREATE TABLE relationship (
              rid INTEGER PRIMARY KEY,
              relationship_type TEXT NOT NULL
            )
            SQL);
        $before = $this->getColumnNames($database);

        try {
            (new RelationshipSchemaManager($database))->ensure();
            self::fail('Incomplete relationship schema must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
        }

        self::assertSame($before, $this->getColumnNames($database));
        self::assertSame([], $this->getIndexNames($database));
    }

    #[Test]
    public function ensure_accepts_the_complete_coordinated_schema_without_mutating_it(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::relationship($database);
        $beforeColumns = $this->getColumnNames($database);
        $beforeIndexes = $this->getIndexNames($database);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();
        $manager->ensure();

        self::assertSame($beforeColumns, $this->getColumnNames($database));
        self::assertSame($beforeIndexes, $this->getIndexNames($database));
        self::assertSame([
            'relationship_from_status_idx',
            'relationship_temporal_idx',
            'relationship_to_status_idx',
            'relationship_type_status_idx',
        ], $beforeIndexes);
    }

    /** @return list<string> */
    private function getColumnNames(DBALDatabase $database): array
    {
        $columns = [];
        foreach ($database->query("PRAGMA table_info('relationship')") as $row) {
            $columns[] = $row['name'];
        }

        return $columns;
    }

    /** @return list<string> */
    private function getIndexNames(DBALDatabase $database): array
    {
        $names = [];
        foreach ($database->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'relationship' ORDER BY name") as $row) {
            $names[] = $row['name'];
        }

        return $names;
    }
}
