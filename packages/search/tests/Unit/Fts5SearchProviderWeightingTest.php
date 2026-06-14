<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Document\SearchDocument;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchRequest;

/**
 * Per-column bm25 title weighting: a title-column match must be able to
 * outrank a body-only match. This is the docs-search relevance fix —
 * "How do I add an entity type?" should surface the spec titled "Entity
 * system", not an access-control spec that merely repeats the phrase in body.
 *
 * @covers \Waaseyaa\Search\Fts5\Fts5SearchProvider
 */
#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderWeightingTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();

        // A title-match doc: "entity" lives in the (short) title; the body
        // mentions "entity type" once, in passing.
        $this->indexer->index(new SearchDocument(
            id: 'spec:entity-system',
            title: 'Entity system',
            body: 'Register an entity type through the manager. Fields belong to bundles and storage persists data.',
            metadata: ['entity_type' => 'spec'],
        ));

        // A body-only doc: the title is unrelated, but the body mentions the
        // query phrase more often, so raw body frequency favours it.
        $this->indexer->index(new SearchDocument(
            id: 'spec:access-control',
            title: 'Access control',
            body: 'Access checks run per entity type. The policy decides each entity type grant for a request.',
            metadata: ['entity_type' => 'spec'],
        ));

        // Unrelated docs so "entity"/"type" are discriminating terms (real IDF),
        // mirroring a corpus of many specs rather than two near-duplicates.
        $this->indexer->index(new SearchDocument('spec:routing', 'Routing', 'The router matches a request path to a controller and middleware stack.', ['entity_type' => 'spec']));
        $this->indexer->index(new SearchDocument('spec:cache', 'Cache', 'The cache stores computed values in memory with a time to live and tags.', ['entity_type' => 'spec']));
        $this->indexer->index(new SearchDocument('spec:mail', 'Mail', 'The mailer renders a template and sends a message through a transport.', ['entity_type' => 'spec']));
        $this->indexer->index(new SearchDocument('spec:queue', 'Queue', 'A job is dispatched onto a queue and a worker processes it asynchronously.', ['entity_type' => 'spec']));
    }

    #[Test]
    public function default_weights_rank_the_body_frequency_match_first(): void
    {
        $provider = new Fts5SearchProvider($this->database, $this->indexer);

        $result = $provider->search(new SearchRequest('entity type'));

        $this->assertSame(2, $result->totalHits);
        $this->assertSame('spec:access-control', $result->hits[0]->id, 'Without title weighting, raw body frequency wins.');
    }

    #[Test]
    public function title_weighting_floats_the_title_match_above_the_body_only_match(): void
    {
        $provider = new Fts5SearchProvider($this->database, $this->indexer, null, 10.0, 1.0);

        $result = $provider->search(new SearchRequest('entity type'));

        $this->assertSame(2, $result->totalHits);
        $this->assertSame('spec:entity-system', $result->hits[0]->id, 'A title-column match must outrank a body-only match.');
        $this->assertGreaterThan($result->hits[1]->score, $result->hits[0]->score);
    }
}
