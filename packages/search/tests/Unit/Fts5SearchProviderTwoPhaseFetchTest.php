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
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderTwoPhaseFetchTest extends TestCase
{
    #[Test]
    public function raw_scan_fetches_only_pointers_and_canonical_resolver_supplies_page_content(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);
        $this->index($indexer, 'node:1', '2026-01-01T00:00:00Z');
        $this->index($indexer, 'node:2', '2026-01-02T00:00:00Z');
        $this->index($indexer, 'node:3', '2026-01-03T00:00:00Z');

        $recording = new RecordingSearchDatabase($database);
        $resolver = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                if ($reference->documentId === 'node:2') {
                    return null;
                }
                $day = $reference->documentId === 'node:1' ? '01' : '03';

                return new SearchCandidateProjection(
                    id: $reference->documentId,
                    entityType: 'node',
                    title: 'Searchable',
                    body: 'Canonical searchable body',
                    crawledAt: "2026-01-{$day}T00:00:00Z",
                );
            }
        };
        $provider = new Fts5SearchProvider($recording, $indexer, $resolver);

        $result = $provider->search(new SearchRequest(
            'Searchable',
            new SearchFilters(sortField: 'created_at', sortOrder: 'asc'),
            page: 2,
            pageSize: 1,
            includeFacets: false,
        ), \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());

        self::assertSame('node:3', $result->hits[0]->id);
        $searchQueries = array_values(array_filter(
            $recording->queries,
            static fn(array $query): bool => str_contains($query['sql'], 'search_index MATCH'),
        ));
        self::assertCount(1, $searchQueries, 'The raw index may only generate opaque candidate pointers.');

        [$scan] = $searchQueries;
        self::assertStringNotContainsString('snippet(', $scan['sql']);
        self::assertStringNotContainsString('si.title', $scan['sql']);
        self::assertStringNotContainsString('si.body', $scan['sql']);
        self::assertStringNotContainsString('m.*', $scan['sql']);
        self::assertStringContainsString('LIMIT :scanCap', $scan['sql']);
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
