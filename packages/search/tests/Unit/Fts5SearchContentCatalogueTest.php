<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchContentCatalogue;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;

#[CoversClass(Fts5SearchContentCatalogue::class)]
final class Fts5SearchContentCatalogueTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();
        $this->index('node:1', '/public', 'Public');
        $this->index('node:2', '/private', 'Private');
    }

    #[Test]
    public function listing_and_direct_reads_use_only_exact_principal_safe_projections(): void
    {
        $seen = [];
        $resolver = new class($this->database, $seen) implements SearchCandidateResolverInterface {
            private readonly \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver $inner;

            /** @param list<AuthorizationPrincipalInterface> $seen */
            public function __construct(DBALDatabase $database, private array &$seen)
            {
                $this->inner = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver(
                    $database,
                    static fn(SearchCandidateReference $reference): bool => $reference->documentId !== 'node:2',
                );
            }

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                $this->seen[] = $principal;

                return $this->inner->resolve($reference, $principal);
            }
        };
        $catalogue = new Fts5SearchContentCatalogue($this->database, $resolver);
        $principal = SearchTestPrincipal::create();

        $listed = $catalogue->list($principal);

        self::assertCount(1, $listed);
        self::assertSame('/public', $listed[0]->url);
        self::assertNull($catalogue->readByPublicPath('/private', $principal));
        self::assertSame('/public', $catalogue->readByPublicPath('/public', $principal)?->url);
        foreach ($seen as $actual) {
            self::assertSame($principal, $actual);
        }
    }

    #[Test]
    public function poisoned_index_urls_are_candidates_only_and_must_match_the_canonical_projection(): void
    {
        $resolver = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return new SearchCandidateProjection($reference->documentId, 'node', 'Canonical', 'Safe', '/canonical');
            }
        };
        $catalogue = new Fts5SearchContentCatalogue($this->database, $resolver);

        self::assertNull($catalogue->readByPublicPath('/public', SearchTestPrincipal::create()));
    }

    #[Test]
    public function listing_scans_and_returns_fixed_bounded_windows_without_counts_or_cursors(): void
    {
        for ($id = 3; $id <= 550; ++$id) {
            $this->index('node:' . $id, '/page-' . $id, 'Page ' . $id);
        }
        $calls = 0;
        $inner = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver($this->database);
        $resolver = new class($inner, $calls) implements SearchCandidateResolverInterface {
            public function __construct(
                private readonly SearchCandidateResolverInterface $inner,
                private int &$calls,
            ) {}

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                ++$this->calls;

                return $this->inner->resolve($reference, $principal);
            }
        };

        $listed = new Fts5SearchContentCatalogue($this->database, $resolver)->list(SearchTestPrincipal::create());

        self::assertCount(50, $listed);
        self::assertSame(50, $calls);
    }

    private function index(string $id, string $url, string $title): void
    {
        $this->indexer->index(new class($id, $url, $title) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $url,
                private readonly string $title,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return ['title' => $this->title, 'body' => $this->title . ' body']; }
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => 'node',
                    'content_type' => 'page',
                    'source_name' => 'site',
                    'url' => $this->url,
                    'created_at' => '2026-08-04T00:00:00Z',
                ];
            }
        });
    }
}
