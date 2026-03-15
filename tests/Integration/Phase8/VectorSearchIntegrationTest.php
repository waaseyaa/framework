<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase8;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EntityEmbedder;
use Waaseyaa\AI\Vector\InMemoryVectorStore;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Node\Node;
use Waaseyaa\Taxonomy\Term;

/**
 * Entity embedding and similarity search with real entities.
 *
 * Exercises: waaseyaa/ai-vector (EntityEmbedder, InMemoryVectorStore,
 * FakeEmbeddingProvider) with waaseyaa/node (Node) and waaseyaa/taxonomy (Term).
 */
#[CoversNothing]
final class VectorSearchIntegrationTest extends TestCase
{
    private FakeEmbeddingProvider $embeddingProvider;
    private InMemoryVectorStore $vectorStore;
    private EntityEmbedder $embedder;

    protected function setUp(): void
    {
        $this->embeddingProvider = new FakeEmbeddingProvider(128);
        $this->vectorStore = new InMemoryVectorStore();
        $this->embedder = new EntityEmbedder($this->embeddingProvider, $this->vectorStore);
    }

    #[Test]
    public function embedMultipleNodesAndSearchForSimilarContent(): void
    {
        $phpNode = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Introduction to PHP Programming',
            'uid' => 1,
        ]);
        $jsNode = new Node([
            'nid' => 2,
            'type' => 'article',
            'title' => 'JavaScript Framework Comparison',
            'uid' => 1,
        ]);
        $phpAdvNode = new Node([
            'nid' => 3,
            'type' => 'article',
            'title' => 'Advanced PHP Design Patterns',
            'uid' => 1,
        ]);

        $this->embedder->embedEntity($phpNode);
        $this->embedder->embedEntity($jsNode);
        $this->embedder->embedEntity($phpAdvNode);

        // Search for PHP-related content.
        $results = $this->embedder->searchSimilar('PHP programming techniques', 3);

        $this->assertCount(3, $results);
        // All results should have scores between 0 and 1.
        foreach ($results as $result) {
            $this->assertGreaterThan(-1.1, $result->score);
            $this->assertLessThanOrEqual(1.0, $result->score);
        }
    }

    #[Test]
    public function embeddedEntityIsStoredAndRetrievable(): void
    {
        $node = new Node([
            'nid' => 10,
            'type' => 'page',
            'title' => 'Stored Embedding Test',
            'uid' => 1,
        ]);

        $embedding = $this->embedder->embedEntity($node);

        $this->assertSame('node', $embedding->entityTypeId);
        $this->assertSame(10, $embedding->entityId);
        $this->assertCount(128, $embedding->vector);
        $this->assertSame('Stored Embedding Test', $embedding->metadata['label']);
        $this->assertSame('page', $embedding->metadata['bundle']);
        $this->assertGreaterThan(0, $embedding->createdAt);

        // Retrieve from store.
        $this->assertTrue($this->vectorStore->has('node', 10));
        $stored = $this->vectorStore->get('node', 10);
        $this->assertNotNull($stored);
        $this->assertSame($embedding->vector, $stored->vector);
    }

    #[Test]
    public function searchWithEntityTypeFilter(): void
    {
        $node1 = new Node([
            'nid' => 1, 'type' => 'article', 'title' => 'PHP Article', 'uid' => 1,
        ]);
        $node2 = new Node([
            'nid' => 2, 'type' => 'article', 'title' => 'JS Article', 'uid' => 1,
        ]);
        $term = new Term([
            'tid' => 1, 'vid' => 'tags', 'name' => 'PHP Tag',
        ]);

        $this->embedder->embedEntity($node1);
        $this->embedder->embedEntity($node2);
        $this->embedder->embedEntity($term);

        // Search without filter: returns all.
        $allResults = $this->embedder->searchSimilar('PHP', 10);
        $this->assertCount(3, $allResults);

        // Search with node filter: only nodes.
        $nodeResults = $this->embedder->searchSimilar('PHP', 10, 'node');
        $this->assertCount(2, $nodeResults);
        foreach ($nodeResults as $result) {
            $this->assertSame('node', $result->embedding->entityTypeId);
        }

        // Search with taxonomy_term filter.
        $termResults = $this->embedder->searchSimilar('PHP', 10, 'taxonomy_term');
        $this->assertCount(1, $termResults);
        $this->assertSame('taxonomy_term', $termResults[0]->embedding->entityTypeId);
    }

    #[Test]
    public function removeEntityEmbeddingExcludesFromSearch(): void
    {
        $node1 = new Node([
            'nid' => 1, 'type' => 'article', 'title' => 'Keep This', 'uid' => 1,
        ]);
        $node2 = new Node([
            'nid' => 2, 'type' => 'article', 'title' => 'Remove This', 'uid' => 1,
        ]);

        $this->embedder->embedEntity($node1);
        $this->embedder->embedEntity($node2);

        $this->assertTrue($this->vectorStore->has('node', 2));

        // Remove node 2's embedding.
        $this->embedder->removeEntity('node', 2);

        $this->assertFalse($this->vectorStore->has('node', 2));
        $this->assertNull($this->vectorStore->get('node', 2));

        // Search should only return node 1.
        $results = $this->embedder->searchSimilar('article', 10, 'node');
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->embedding->entityId);
    }

    #[Test]
    public function fakeEmbeddingProviderIsDeterministic(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';

        $vector1 = $this->embeddingProvider->embed($text);
        $vector2 = $this->embeddingProvider->embed($text);

        $this->assertSame($vector1, $vector2);
        $this->assertCount(128, $vector1);

        // Different text produces different vector.
        $vector3 = $this->embeddingProvider->embed('A completely different sentence');
        $this->assertNotSame($vector1, $vector3);
    }

    #[Test]
    public function fakeEmbeddingProviderBatchEmbedding(): void
    {
        $texts = ['Hello world', 'Goodbye world', 'Hello world'];

        $vectors = $this->embeddingProvider->embedBatch($texts);

        $this->assertCount(3, $vectors);
        // Same text produces same vector.
        $this->assertSame($vectors[0], $vectors[2]);
        // Different text produces different vector.
        $this->assertNotSame($vectors[0], $vectors[1]);
    }

    #[Test]
    public function embedDifferentEntityTypesAndFilterByType(): void
    {
        // Create nodes.
        for ($i = 1; $i <= 3; $i++) {
            $node = new Node([
                'nid' => $i,
                'type' => 'article',
                'title' => "Article {$i} about Programming",
                'uid' => 1,
            ]);
            $this->embedder->embedEntity($node);
        }

        // Create terms.
        for ($i = 1; $i <= 2; $i++) {
            $term = new Term([
                'tid' => $i,
                'vid' => 'tags',
                'name' => "Tag {$i} Programming",
            ]);
            $this->embedder->embedEntity($term);
        }

        // Search all: 5 results.
        $allResults = $this->embedder->searchSimilar('Programming', 10);
        $this->assertCount(5, $allResults);

        // Search only nodes: 3 results.
        $nodeResults = $this->embedder->searchSimilar('Programming', 10, 'node');
        $this->assertCount(3, $nodeResults);

        // Search only terms: 2 results.
        $termResults = $this->embedder->searchSimilar('Programming', 10, 'taxonomy_term');
        $this->assertCount(2, $termResults);
    }

    #[Test]
    public function searchLimitRespectsMaxResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $node = new Node([
                'nid' => $i,
                'type' => 'article',
                'title' => "Node {$i}",
                'uid' => 1,
            ]);
            $this->embedder->embedEntity($node);
        }

        $results = $this->embedder->searchSimilar('Node', 2);
        $this->assertCount(2, $results);

        $results = $this->embedder->searchSimilar('Node', 10);
        $this->assertCount(5, $results);
    }

    #[Test]
    public function reEmbeddingOverwritesPreviousVector(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Original Title',
            'uid' => 1,
        ]);
        $embedding1 = $this->embedder->embedEntity($node);

        // Change title and re-embed.
        $node->setTitle('Completely Different Title');
        $embedding2 = $this->embedder->embedEntity($node);

        // Vectors should differ since text changed.
        $this->assertNotSame($embedding1->vector, $embedding2->vector);

        // Store should only have one entry.
        $stored = $this->vectorStore->get('node', 1);
        $this->assertSame($embedding2->vector, $stored->vector);

        // Search should return only one result for node type.
        $results = $this->embedder->searchSimilar('title', 10, 'node');
        $this->assertCount(1, $results);
    }

    #[Test]
    public function cosineSimilarityOfIdenticalVectorsIsOne(): void
    {
        $vector = $this->embeddingProvider->embed('test');
        $similarity = InMemoryVectorStore::cosineSimilarity($vector, $vector);
        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }
}
