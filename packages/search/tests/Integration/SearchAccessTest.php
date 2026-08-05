<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;

#[CoversClass(Fts5SearchProvider::class)]
final class SearchAccessTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexDoc('node:1', 'Restricted Label Report', 'secretterm indexed body');
        $this->indexDoc('node:2', 'Denied Report', 'publicterm indexed body');
        $this->indexDoc('node:3', 'Malformed Report', 'publicterm indexed body');
        $this->indexDoc('spec:overview', 'Unknown Report', 'publicterm indexed body', 'document');
        $this->indexDoc('', 'Corrupt Report', 'publicterm indexed body');

        $resolver = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                if ($reference->documentId === 'node:2') {
                    throw new \RuntimeException('Broken application projection.');
                }
                if ($reference->documentId === 'node:3') {
                    return new SearchCandidateProjection('node:3', 'node', "invalid \xC3\x28", '');
                }
                if ($reference->documentId !== 'node:1') {
                    return null;
                }

                return new SearchCandidateProjection(
                    id: 'node:1',
                    entityType: 'node',
                    title: 'Public Report',
                    body: 'publicterm canonical summary',
                    url: '/node/1',
                    sourceName: 'public',
                    contentType: 'article',
                    topics: ['visible'],
                );
            }
        };
        $this->provider = new Fts5SearchProvider($this->database, $this->indexer, $resolver);
    }

    #[Test]
    public function raw_restricted_label_and_body_never_reach_a_hit(): void
    {
        $result = $this->provider->search(new SearchRequest('Report'), SearchTestPrincipal::create());

        self::assertSame(1, $result->totalHits);
        self::assertSame('Public Report', $result->hits[0]->title);
        self::assertSame('public', $result->hits[0]->sourceName);
        self::assertSame('article', $result->hits[0]->contentType);
        self::assertSame(['visible'], $result->hits[0]->topics);
        self::assertStringNotContainsString('Restricted Label', $result->hits[0]->title);
        self::assertStringNotContainsString('secretterm', $result->hits[0]->highlight);
        self::assertNull($result->getFacet('source_name')?->buckets[1] ?? null);
        self::assertSame('public', $result->getFacet('source_name')?->buckets[0]->key);
        self::assertSame('article', $result->getFacet('content_type')?->buckets[0]->key);
        self::assertSame('visible', $result->getFacet('topics')?->buckets[0]->key);
    }

    #[Test]
    public function a_match_existing_only_in_the_raw_protected_body_is_not_counted(): void
    {
        $result = $this->provider->search(new SearchRequest('secretterm'), SearchTestPrincipal::create());

        self::assertSame(0, $result->totalHits);
        self::assertSame([], $result->hits);
        self::assertSame([], $result->facets);
    }

    #[Test]
    public function denied_and_unknown_sources_do_not_affect_counts_or_pages(): void
    {
        $result = $this->provider->search(
            new SearchRequest('Report', page: 1, pageSize: 1),
            SearchTestPrincipal::create(),
        );

        self::assertSame(1, $result->totalHits);
        self::assertSame(1, $result->totalPages);
        self::assertSame('node:1', $result->hits[0]->id);
    }

    private function indexDoc(string $id, string $title, string $body, string $entityType = 'node'): void
    {
        $this->indexer->index(new class ($id, $title, $body, $entityType) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $title,
                private readonly string $body,
                private readonly string $entityType,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return ['title' => $this->title, 'body' => $this->body]; }
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => $this->entityType,
                    'content_type' => 'secret',
                    'source_name' => 'restricted',
                    'topics' => ['hidden'],
                ];
            }
        });
    }
}
