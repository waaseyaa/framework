<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderCandidateWindowContractTest extends TestCase
{
    #[Test]
    public function an_exhausted_all_denied_window_is_empty_and_explicitly_incomplete(): void
    {
        $database = new CandidateWindowDatabase(self::candidateRows(1_001));
        $resolver = new class implements SearchCandidateResolverInterface {
            public int $calls = 0;

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                ++$this->calls;

                return null;
            }
        };

        $result = $this->provider($database, $resolver)->search(
            new SearchRequest('needle'),
            self::principal('community-a'),
        );

        self::assertSame(1_000, $resolver->calls, 'The truncation sentinel must never reach authorization.');
        self::assertSame(1, $database->pointerQueries);
        self::assertSame(0, $result->totalHits);
        self::assertSame(0, $result->totalPages);
        self::assertSame([], $result->hits);
        self::assertSame([], $result->facets);
        self::assertFalse($result->isComplete, 'Completeness describes raw window exhaustion, not visible cardinality.');
    }

    #[Test]
    public function filters_and_non_relevance_sorts_are_scoped_to_the_top_thousand_raw_candidates(): void
    {
        $database = new CandidateWindowDatabase(self::candidateRows(1_001));
        $resolver = new class implements SearchCandidateResolverInterface {
            /** @var list<string> */
            public array $resolved = [];

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                $this->resolved[] = $reference->documentId;
                $number = (int) substr($reference->documentId, strlen('node:'));

                return new SearchCandidateProjection(
                    id: $reference->documentId,
                    entityType: 'node',
                    title: 'needle',
                    body: 'needle safe body',
                    crawledAt: sprintf('%04d-01-01T00:00:00Z', 2_000 + $number),
                    contentType: $number === 1_001 ? 'outside-window' : 'inside-window',
                    topics: [$number === 1_001 ? 'outside-window' : 'inside-window'],
                );
            }
        };
        $provider = $this->provider($database, $resolver);
        $principal = self::principal('community-a');

        $filtered = $provider->search(
            new SearchRequest('needle', new SearchFilters(contentType: 'outside-window')),
            $principal,
        );
        $newest = $provider->search(
            new SearchRequest(
                'needle',
                new SearchFilters(sortField: 'created_at', sortOrder: 'desc'),
                pageSize: 1,
            ),
            $principal,
        );

        self::assertSame(0, $filtered->totalHits);
        self::assertSame([], $filtered->hits);
        self::assertFalse($filtered->isComplete);
        self::assertSame(1_000, $newest->totalHits);
        self::assertSame(1_000, $newest->totalPages);
        self::assertSame('node:1000', $newest->hits[0]->id, 'The newest result is selected only from the inspected window.');
        self::assertFalse($newest->isComplete);
        self::assertNotContains('node:1001', $resolver->resolved, 'The sentinel must not influence filtering or sorting.');
        self::assertCount(2_000, $resolver->resolved);
        self::assertSame(2, $database->pointerQueries);
    }

    #[Test]
    public function candidate_resolution_is_community_scoped_and_never_reused_between_principals(): void
    {
        $database = new CandidateWindowDatabase([
            ['document_id' => 'node:community-a', 'entity_type' => 'node', 'schema_version' => 'schema-v2'],
            ['document_id' => 'node:community-b', 'entity_type' => 'node', 'schema_version' => 'schema-v2'],
        ]);
        $resolver = new class implements SearchCandidateResolverInterface {
            public int $calls = 0;

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                ++$this->calls;
                if ($reference->documentId !== 'node:' . $principal->communityId()) {
                    return null;
                }

                return new SearchCandidateProjection(
                    id: $reference->documentId,
                    entityType: 'node',
                    title: 'needle',
                    body: 'needle community-safe body',
                );
            }
        };
        $provider = $this->provider($database, $resolver);

        $communityA = $provider->search(new SearchRequest('needle'), self::principal('community-a'));
        $communityB = $provider->search(new SearchRequest('needle'), self::principal('community-b'));
        $communityARepeat = $provider->search(new SearchRequest('needle'), self::principal('community-a'));

        self::assertSame(['node:community-a'], array_column($communityA->hits, 'id'));
        self::assertSame(['node:community-b'], array_column($communityB->hits, 'id'));
        self::assertSame(['node:community-a'], array_column($communityARepeat->hits, 'id'));
        self::assertTrue($communityA->isComplete);
        self::assertTrue($communityB->isComplete);
        self::assertSame(6, $resolver->calls, 'Every request must re-resolve every pointer for its exact principal.');
        self::assertSame(3, $database->pointerQueries);
    }

    #[Test]
    public function an_accessible_stale_schema_pointer_is_reported_without_changing_visibility(): void
    {
        $database = new CandidateWindowDatabase([
            ['document_id' => 'node:1', 'entity_type' => 'node', 'schema_version' => 'schema-v1'],
        ]);
        $resolver = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return new SearchCandidateProjection($reference->documentId, 'node', 'needle', 'needle safe body');
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Search index contains stale accessible documents. Run search:reindex to rebuild.');

        $result = $this->provider($database, $resolver, $logger)->search(
            new SearchRequest('needle'),
            self::principal('community-a'),
        );

        self::assertSame(['node:1'], array_column($result->hits, 'id'));
        self::assertTrue($result->isComplete);
    }

    private function provider(
        DatabaseInterface $database,
        SearchCandidateResolverInterface $resolver,
        ?LoggerInterface $logger = null,
    ): Fts5SearchProvider {
        $indexer = new class implements SearchIndexerInterface {
            public function index(SearchIndexableInterface $item): void { throw new \LogicException('Read-only test indexer.'); }
            public function remove(string $documentId): void { throw new \LogicException('Read-only test indexer.'); }
            public function removeAll(): void { throw new \LogicException('Read-only test indexer.'); }
            public function getSchemaVersion(): string { return 'schema-v2'; }
        };

        return new Fts5SearchProvider($database, $indexer, $resolver, $logger);
    }

    /** @return list<array{document_id: string, entity_type: string, schema_version: string}> */
    private static function candidateRows(int $count): array
    {
        $rows = [];
        for ($candidate = 1; $candidate <= $count; ++$candidate) {
            $rows[] = [
                'document_id' => sprintf('node:%04d', $candidate),
                'entity_type' => 'node',
                'schema_version' => 'schema-v2',
            ];
        }

        return $rows;
    }

    private static function principal(string $communityId): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: ['member'],
            permissions: [],
            claimsGeneration: 'shared-generation',
            tenantId: 'tenant-a',
            communityId: $communityId,
        );
    }
}

final class CandidateWindowDatabase implements DatabaseInterface
{
    public int $pointerQueries = 0;

    /** @param list<array{document_id: string, entity_type: string, schema_version: string}> $candidateRows */
    public function __construct(private readonly array $candidateRows) {}

    public function select(string $table, string $alias = ''): SelectInterface { throw new \LogicException('Unsupported test query.'); }
    public function insert(string $table): InsertInterface { throw new \LogicException('Unsupported test query.'); }
    public function update(string $table): UpdateInterface { throw new \LogicException('Unsupported test query.'); }
    public function delete(string $table): DeleteInterface { throw new \LogicException('Unsupported test query.'); }
    public function schema(): SchemaInterface { throw new \LogicException('Unsupported test query.'); }
    public function transaction(string $name = ''): TransactionInterface { throw new \LogicException('Unsupported test query.'); }

    public function query(string $sql, array $args = []): \Traversable
    {
        if (str_contains($sql, 'sqlite_master')) {
            return new \ArrayIterator([['name' => 'search_index']]);
        }
        if (!str_contains($sql, 'search_index MATCH')) {
            throw new \LogicException('Unexpected test query.');
        }
        if (($args['scanLimit'] ?? null) !== 1_001) {
            throw new \LogicException('Candidate scan did not preserve the public work bound.');
        }
        ++$this->pointerQueries;

        return new \ArrayIterator(array_slice($this->candidateRows, 0, 1_001));
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
