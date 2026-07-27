<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;

/**
 * Regression: #2056 — schema operations must survive SQLite databases that
 * contain FTS5 virtual tables.
 *
 * FTS5 virtual tables (the search package's `search_index`) and their shadow
 * tables expose typeless columns that DBAL's SQLiteSchemaManager cannot parse
 * (`strtolower(null)` TypeError / AssertionError). Every DBALSchema operation
 * that introspects the catalog (`addField()` and friends) therefore crashed
 * kernel boot on any real app DB once a search index existed — the alpha.266
 * portfolio blocker. Virtual/shadow tables and SQLite internals are now
 * invisible to the DBAL schema surface via a connection-level schema-assets
 * filter; raw SQL (`sqlite_master`) remains the only correct interface to
 * them, which is what the search package already uses.
 */
#[CoversClass(DBALSchema::class)]
#[CoversClass(DBALDatabase::class)]
final class DBALSchemaVirtualTableTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        if (!$this->sqliteHasFts5()) {
            self::markTestSkipped('SQLite build lacks FTS5 support.');
        }

        $this->db->schema()->createTable('article', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            ],
            'primary key' => ['id'],
        ]);
        // Materialize sqlite_sequence (autoincrement bookkeeping — also a
        // typeless-column internal table DBAL must never introspect).
        $this->db->insert('article')->fields(['title' => 'seed'])->execute();

        $this->db->query('CREATE VIRTUAL TABLE search_index USING fts5(entity_type, entity_id, title, body)');
        $this->db->query("INSERT INTO search_index (entity_type, entity_id, title, body) VALUES ('article', '1', 'seed', 'body')");
    }

    #[Test]
    public function addFieldSucceedsWhenAnFts5SearchIndexExists(): void
    {
        $this->db->schema()->addField('article', 'subtitle', [
            'type' => 'varchar',
            'length' => 255,
        ]);

        self::assertTrue($this->db->schema()->fieldExists('article', 'subtitle'));
    }

    #[Test]
    public function addIndexSucceedsWhenAnFts5SearchIndexExists(): void
    {
        $this->db->schema()->addIndex('article', 'article_title_idx', ['title']);

        self::assertTrue(true); // reaching here without a throw is the assertion
    }

    #[Test]
    public function listTableNamesExcludesVirtualShadowAndInternalTables(): void
    {
        $names = $this->db->schema()->listTableNames();

        self::assertContains('article', $names);
        self::assertNotContains('search_index', $names);
        foreach ($names as $name) {
            self::assertStringStartsNotWith('search_index_', $name);
            self::assertStringStartsNotWith('sqlite_', $name);
        }
    }

    #[Test]
    public function virtualTableCreatedAfterFirstIntrospectionIsStillFiltered(): void
    {
        // Snapshot the catalog before a second virtual table exists…
        $this->db->schema()->listTableNames();

        // …then create one mid-process (FrankenPHP worker shape: the search
        // indexer materializes its table lazily after boot).
        $this->db->query('CREATE VIRTUAL TABLE late_index USING fts5(body)');

        $this->db->schema()->addField('article', 'summary', ['type' => 'varchar', 'length' => 255]);

        self::assertTrue($this->db->schema()->fieldExists('article', 'summary'));
        self::assertNotContains('late_index', $this->db->schema()->listTableNames());
    }

    private function sqliteHasFts5(): bool
    {
        try {
            $this->db->query('CREATE VIRTUAL TABLE fts5_probe USING fts5(x)');
            $this->db->query('DROP TABLE fts5_probe');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
