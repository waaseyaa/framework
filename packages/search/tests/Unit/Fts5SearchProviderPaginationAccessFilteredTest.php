<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Access\SearchAccessChecker;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * Regression test for the pagination split (audit L3-search.md, #1915, R16):
 * `Fts5SearchProvider::search()` computed `totalHits`/facets from an
 * access-filtered scan but fetched the actual page from an independent,
 * unfiltered `ORDER BY ... LIMIT/OFFSET` query. A fixed-size OFFSET walked
 * the WRONG sequence (all candidates, forbidden included) instead of the
 * filtered one, so an accessible document sharing a page window with a
 * forbidden document was silently dropped from every page — `totalPages`
 * promised a page that could never fully materialize.
 *
 * Fixture: three documents all matching the query, sorted deterministically
 * by `created_at` ascending (not relevance, to avoid bm25 tie-break
 * ambiguity): doc1 (allowed), doc2 (DENIED — sits inside page 1's window),
 * doc3 (allowed). With `pageSize=1`:
 *   - Pre-fix: page 1 returns doc1 (correct by luck), but page 2's SQL fetch
 *     (OFFSET 1 over the UNFILTERED order) lands on doc2, which then gets
 *     filtered out post-fetch — so page 2 comes back EMPTY even though
 *     totalPages=2 (from totalHits=2) promises a second page exists, and
 *     doc3 (the second accessible document) never surfaces on any page.
 *   - Post-fix: page 1 returns doc1, page 2 returns doc3 — every accessible
 *     document is reachable, and totalHits/totalPages stay consistent with
 *     what paging actually returns.
 */
#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderPaginationAccessFilteredTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();

        // doc2 sits between doc1 and doc3 in created_at order and is denied,
        // so it occupies the middle slot of a pageSize=1 walk.
        $this->indexItem('node:1', '2026-01-01T00:00:00Z');
        $this->indexItem('node:2', '2026-01-02T00:00:00Z');
        $this->indexItem('node:3', '2026-01-03T00:00:00Z');

        $checker = new class implements SearchAccessChecker {
            public function canView(string $documentId, string $entityType): bool
            {
                return $documentId !== 'node:2';
            }
        };

        $this->provider = new Fts5SearchProvider($this->database, $this->indexer, accessChecker: $checker);
    }

    #[Test]
    public function every_accessible_document_is_reachable_across_pages_with_a_consistent_total(): void
    {
        $filters = new SearchFilters(sortField: 'created_at', sortOrder: 'asc');

        $page1 = $this->provider->search(new SearchRequest('Searchable', $filters, page: 1, pageSize: 1));
        $page2 = $this->provider->search(new SearchRequest('Searchable', $filters, page: 2, pageSize: 1));

        // totalHits/totalPages reflect only the 2 accessible documents,
        // consistently across both requests.
        self::assertSame(2, $page1->totalHits);
        self::assertSame(2, $page1->totalPages);
        self::assertSame(2, $page2->totalHits);
        self::assertSame(2, $page2->totalPages);

        // Page 1: the first accessible document (doc2 is denied, doc1 sorts first).
        self::assertCount(1, $page1->hits, 'Page 1 must contain a hit, not be empty.');
        self::assertSame('node:1', $page1->hits[0]->id);

        // Page 2: the pre-fix bug returned an EMPTY page here (the unfiltered
        // OFFSET landed on the denied doc2, dropped post-fetch) even though
        // totalPages said a second page existed. Fixed: doc3 surfaces here.
        self::assertCount(1, $page2->hits, 'Page 2 must contain the second accessible document, not be empty.');
        self::assertSame('node:3', $page2->hits[0]->id);

        // The denied document must never appear on any page.
        self::assertNotSame('node:2', $page1->hits[0]->id ?? null);
        self::assertNotSame('node:2', $page2->hits[0]->id ?? null);
    }

    private function indexItem(string $id, string $createdAt): void
    {
        $this->indexer->index(new class ($id, $createdAt) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $createdAt,
            ) {}

            public function getSearchDocumentId(): string
            {
                return $this->id;
            }

            /** @return array<string, string> */
            public function toSearchDocument(): array
            {
                return ['title' => 'Searchable Item', 'body' => 'Searchable content'];
            }

            /** @return array<string, mixed> */
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => 'node',
                    'content_type' => 'article',
                    'source_name' => 'blog',
                    'quality_score' => 0,
                    'topics' => [],
                    'url' => '',
                    'og_image' => '',
                    'created_at' => $this->createdAt,
                ];
            }
        });
    }
}
