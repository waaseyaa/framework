<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\EntityEmbedding;
use Aurora\AI\Vector\InMemoryVectorStore;
use Aurora\AI\Vector\SimilarityResult;
use PHPUnit\Framework\TestCase;

final class InMemoryVectorStoreTest extends TestCase
{
    private InMemoryVectorStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryVectorStore();
    }

    public function testStoreAndRetrieve(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.1, 0.2, 0.3],
            metadata: ['title' => 'Article'],
        );

        $this->store->store($embedding);

        $retrieved = $this->store->get('node', 1);
        $this->assertNotNull($retrieved);
        $this->assertSame('node', $retrieved->entityTypeId);
        $this->assertSame(1, $retrieved->entityId);
        $this->assertSame([0.1, 0.2, 0.3], $retrieved->vector);
        $this->assertSame(['title' => 'Article'], $retrieved->metadata);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->store->get('node', 999));
    }

    public function testHasReturnsTrueWhenExists(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.1],
        );

        $this->store->store($embedding);
        $this->assertTrue($this->store->has('node', 1));
    }

    public function testHasReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->store->has('node', 999));
    }

    public function testDelete(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.1],
        );

        $this->store->store($embedding);
        $this->assertTrue($this->store->has('node', 1));

        $this->store->delete('node', 1);
        $this->assertFalse($this->store->has('node', 1));
        $this->assertNull($this->store->get('node', 1));
    }

    public function testDeleteNonExistentIsNoOp(): void
    {
        // Should not throw.
        $this->store->delete('node', 999);
        $this->assertFalse($this->store->has('node', 999));
    }

    public function testSearchByCosineSimilarity(): void
    {
        // Store two embeddings: one similar to the query, one orthogonal.
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [1.0, 0.0, 0.0], // Points along x-axis.
        ));
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 2,
            vector: [0.0, 1.0, 0.0], // Points along y-axis.
        ));

        // Query along x-axis should match entity 1 best.
        $results = $this->store->search([1.0, 0.0, 0.0]);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]->embedding->entityId);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.0001);
        $this->assertSame(2, $results[1]->embedding->entityId);
        $this->assertEqualsWithDelta(0.0, $results[1]->score, 0.0001);
    }

    public function testSearchWithLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $vector = array_fill(0, 3, 0.0);
            $vector[0] = (float) $i;
            $this->store->store(new EntityEmbedding(
                entityTypeId: 'node',
                entityId: $i,
                vector: $vector,
            ));
        }

        $results = $this->store->search([1.0, 0.0, 0.0], limit: 2);
        $this->assertCount(2, $results);
    }

    public function testSearchWithEntityTypeFilter(): void
    {
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [1.0, 0.0],
        ));
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'taxonomy_term',
            entityId: 2,
            vector: [1.0, 0.0],
        ));

        $results = $this->store->search([1.0, 0.0], entityTypeId: 'node');
        $this->assertCount(1, $results);
        $this->assertSame('node', $results[0]->embedding->entityTypeId);
    }

    public function testSearchEmptyStoreReturnsEmpty(): void
    {
        $results = $this->store->search([1.0, 0.0, 0.0]);
        $this->assertSame([], $results);
    }

    public function testOverwriteExistingEmbedding(): void
    {
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [1.0, 0.0],
            metadata: ['version' => 1],
        ));

        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.0, 1.0],
            metadata: ['version' => 2],
        ));

        $retrieved = $this->store->get('node', 1);
        $this->assertNotNull($retrieved);
        $this->assertSame([0.0, 1.0], $retrieved->vector);
        $this->assertSame(['version' => 2], $retrieved->metadata);
    }

    public function testSearchResultsSortedByScoreDescending(): void
    {
        // Create three vectors at different angles from query.
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.0, 1.0], // Orthogonal.
        ));
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 2,
            vector: [0.7, 0.7], // 45 degrees.
        ));
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 3,
            vector: [1.0, 0.0], // Identical direction.
        ));

        $results = $this->store->search([1.0, 0.0]);

        $this->assertSame(3, $results[0]->embedding->entityId);
        $this->assertSame(2, $results[1]->embedding->entityId);
        $this->assertSame(1, $results[2]->embedding->entityId);

        // Verify scores are actually descending.
        $this->assertGreaterThan($results[1]->score, $results[0]->score);
        $this->assertGreaterThan($results[2]->score, $results[1]->score);
    }

    public function testCosineSimilarityWithZeroVector(): void
    {
        $score = InMemoryVectorStore::cosineSimilarity([0.0, 0.0], [1.0, 0.0]);
        $this->assertSame(0.0, $score);
    }

    public function testCosineSimilarityIdenticalVectors(): void
    {
        $score = InMemoryVectorStore::cosineSimilarity([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]);
        $this->assertEqualsWithDelta(1.0, $score, 0.0001);
    }

    public function testCosineSimilarityOppositeVectors(): void
    {
        $score = InMemoryVectorStore::cosineSimilarity([1.0, 0.0], [-1.0, 0.0]);
        $this->assertEqualsWithDelta(-1.0, $score, 0.0001);
    }

    public function testStringEntityId(): void
    {
        $this->store->store(new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 'uuid-123',
            vector: [1.0],
        ));

        $this->assertTrue($this->store->has('node', 'uuid-123'));
        $this->assertNotNull($this->store->get('node', 'uuid-123'));
    }
}
