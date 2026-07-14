<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderTwoPhaseFetchTest extends TestCase
{
    #[Test]
    public function access_scan_is_lightweight_then_snippets_are_fetched_only_for_selected_page_ids(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);
        $this->index($indexer, 'node:1', '2026-01-01T00:00:00Z');
        $this->index($indexer, 'node:2', '2026-01-02T00:00:00Z');
        $this->index($indexer, 'node:3', '2026-01-03T00:00:00Z');

        $recording = new RecordingSearchDatabase($database);
        $checker = new class implements SearchAccessChecker {
            public function canView(string $documentId, string $entityType): bool
            {
                return $documentId !== 'node:2';
            }
        };
        $provider = new Fts5SearchProvider($recording, $indexer, accessChecker: $checker);

        $result = $provider->search(new SearchRequest(
            'Searchable',
            new SearchFilters(sortField: 'created_at', sortOrder: 'asc'),
            page: 2,
            pageSize: 1,
            includeFacets: false,
        ));

        self::assertSame('node:3', $result->hits[0]->id);
        $searchQueries = array_values(array_filter(
            $recording->queries,
            static fn(array $query): bool => str_contains($query['sql'], 'search_index MATCH'),
        ));
        self::assertCount(2, $searchQueries, 'Access search must use one bounded scan and one targeted page fetch.');

        [$scan, $fetch] = $searchQueries;
        self::assertStringNotContainsString('snippet(', $scan['sql']);
        self::assertStringNotContainsString('si.title', $scan['sql']);
        self::assertStringContainsString('LIMIT :scanCap', $scan['sql']);

        self::assertStringContainsString('snippet(', $fetch['sql']);
        self::assertStringContainsString('m.document_id IN (:page_0)', $fetch['sql']);
        self::assertSame('node:3', $fetch['args']['page_0']);
        self::assertNotContains('node:2', $fetch['args'], 'Denied IDs must never reach the snippet fetch.');
    }

    private function index(Fts5SearchIndexer $indexer, string $id, string $createdAt): void
    {
        $indexer->index(new class ($id, $createdAt) implements SearchIndexableInterface {
            public function __construct(private readonly string $id, private readonly string $createdAt) {}
            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return ['title' => 'Searchable', 'body' => str_repeat('large body ', 100)]; }
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => 'node',
                    'created_at' => $this->createdAt,
                ];
            }
        });
    }
}

final class RecordingSearchDatabase implements DatabaseInterface
{
    /** @var list<array{sql:string,args:array<string|int,mixed>}> */
    public array $queries = [];

    public function __construct(private readonly DatabaseInterface $inner) {}
    public function select(string $table, string $alias = ''): SelectInterface { return $this->inner->select($table, $alias); }
    public function insert(string $table): InsertInterface { return $this->inner->insert($table); }
    public function update(string $table): UpdateInterface { return $this->inner->update($table); }
    public function delete(string $table): DeleteInterface { return $this->inner->delete($table); }
    public function schema(): SchemaInterface { return $this->inner->schema(); }
    public function transaction(string $name = ''): TransactionInterface { return $this->inner->transaction($name); }
    public function query(string $sql, array $args = []): \Traversable
    {
        $this->queries[] = ['sql' => $sql, 'args' => $args];

        return $this->inner->query($sql, $args);
    }
    public function quoteIdentifier(string $identifier): string { return $this->inner->quoteIdentifier($identifier); }
}
