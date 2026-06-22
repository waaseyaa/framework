<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * D-35: the FTS5 schema must be created lazily on first write, never eagerly
 * on construction. The previous wiring ran 5 DDL statements on every kernel
 * boot (SearchServiceProvider resolved the indexer for the subscriber, and the
 * register() factory called ensureSchema() immediately). Pin the laziness so a
 * regression that re-introduces eager DDL fails loudly.
 *
 * @covers \Waaseyaa\Search\Fts5\Fts5SearchIndexer
 */
#[CoversClass(Fts5SearchIndexer::class)]
final class Fts5SearchIndexerLazySchemaTest extends TestCase
{
    #[Test]
    public function constructing_the_indexer_does_not_create_the_schema(): void
    {
        $database = DBALDatabase::createSqlite();

        // Construction alone must run no DDL.
        new Fts5SearchIndexer($database);

        $this->assertSame([], $this->searchTables($database));
    }

    #[Test]
    public function first_index_write_creates_the_schema(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);

        $this->assertSame([], $this->searchTables($database));

        $indexer->index($this->indexable('node:1'));

        $this->assertSame(['search_index', 'search_metadata'], $this->searchTables($database));
    }

    #[Test]
    public function remove_on_a_fresh_database_creates_the_schema_and_does_not_throw(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);

        // remove() must self-ensure schema rather than fail on a missing table.
        $indexer->remove('node:missing');

        $this->assertSame(['search_index', 'search_metadata'], $this->searchTables($database));
    }

    #[Test]
    public function explicit_ensure_schema_is_idempotent(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);

        $indexer->ensureSchema();
        // A second call must be a no-op (in-process marker), not a re-run.
        $indexer->ensureSchema();

        $this->assertSame(['search_index', 'search_metadata'], $this->searchTables($database));
    }

    #[Test]
    public function searching_a_never_written_index_returns_empty_not_an_error(): void
    {
        $database = DBALDatabase::createSqlite();
        // No ensureSchema(), no write — the lazy schema does not exist yet.
        $provider = new Fts5SearchProvider($database, new Fts5SearchIndexer($database));

        // A non-empty query must degrade to an empty result, not throw
        // "no such table: search_index".
        $result = $provider->search(new SearchRequest('anything'));

        $this->assertSame(0, $result->totalHits);
        $this->assertSame([], $result->hits);
        $this->assertSame([], $this->searchTables($database), 'read path must not create the schema');
    }

    /**
     * @return list<string>
     */
    private function searchTables(DBALDatabase $database): array
    {
        $rows = iterator_to_array($database->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('search_index', 'search_metadata') ORDER BY name",
        ));

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    private function indexable(string $documentId): SearchIndexableInterface
    {
        return new class ($documentId) implements SearchIndexableInterface {
            public function __construct(private readonly string $documentId) {}

            public function getSearchDocumentId(): string
            {
                return $this->documentId;
            }

            public function toSearchDocument(): array
            {
                return ['title' => 'Title', 'body' => 'Body'];
            }

            public function toSearchMetadata(): array
            {
                return ['entity_type' => 'node'];
            }
        };
    }
}
