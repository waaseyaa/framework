<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\EntityEmbedder;
use Aurora\AI\Vector\InMemoryVectorStore;
use Aurora\AI\Vector\SimilarityResult;
use Aurora\AI\Vector\Testing\FakeEmbeddingProvider;
use Aurora\Entity\EntityInterface;
use PHPUnit\Framework\TestCase;

final class EntityEmbedderTest extends TestCase
{
    private FakeEmbeddingProvider $provider;
    private InMemoryVectorStore $store;
    private EntityEmbedder $embedder;

    protected function setUp(): void
    {
        $this->provider = new FakeEmbeddingProvider(dimensions: 32);
        $this->store = new InMemoryVectorStore();
        $this->embedder = new EntityEmbedder($this->provider, $this->store);
    }

    public function testEmbedEntityGeneratesAndStoresEmbedding(): void
    {
        $entity = $this->createMockEntity('node', 1, 'Test Article');

        $result = $this->embedder->embedEntity($entity);

        $this->assertSame('node', $result->entityTypeId);
        $this->assertSame(1, $result->entityId);
        $this->assertCount(32, $result->vector);
        $this->assertSame('Test Article', $result->metadata['label']);
        $this->assertSame('article', $result->metadata['bundle']);
        $this->assertGreaterThan(0, $result->createdAt);

        // Verify it's stored.
        $this->assertTrue($this->store->has('node', 1));
    }

    public function testSearchSimilarFindsRelatedEntities(): void
    {
        // Embed two entities.
        $entity1 = $this->createMockEntity('node', 1, 'PHP Programming Guide');
        $entity2 = $this->createMockEntity('node', 2, 'Cooking Recipes');

        $this->embedder->embedEntity($entity1);
        $this->embedder->embedEntity($entity2);

        // Search should return all embedded entities.
        $results = $this->embedder->searchSimilar('PHP Programming Guide');

        $this->assertCount(2, $results);

        // Results should be sorted by score descending.
        $this->assertGreaterThanOrEqual($results[1]->score, $results[0]->score);

        // Each result should have an embedding with the correct entity type.
        foreach ($results as $result) {
            $this->assertSame('node', $result->embedding->entityTypeId);
            $this->assertInstanceOf(SimilarityResult::class, $result);
        }
    }

    public function testSearchSimilarWithEntityTypeFilter(): void
    {
        $node = $this->createMockEntity('node', 1, 'Test Node');
        $term = $this->createMockEntity('taxonomy_term', 2, 'Test Term', 'tags');

        $this->embedder->embedEntity($node);
        $this->embedder->embedEntity($term);

        $results = $this->embedder->searchSimilar('test', entityTypeId: 'node');

        $this->assertCount(1, $results);
        $this->assertSame('node', $results[0]->embedding->entityTypeId);
    }

    public function testSearchSimilarWithLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $entity = $this->createMockEntity('node', $i, "Article $i");
            $this->embedder->embedEntity($entity);
        }

        $results = $this->embedder->searchSimilar('article', limit: 3);

        $this->assertCount(3, $results);
    }

    public function testRemoveEntity(): void
    {
        $entity = $this->createMockEntity('node', 1, 'Test');
        $this->embedder->embedEntity($entity);

        $this->assertTrue($this->store->has('node', 1));

        $this->embedder->removeEntity('node', 1);

        $this->assertFalse($this->store->has('node', 1));
    }

    private function createMockEntity(
        string $entityTypeId,
        int|string $id,
        string $label,
        string $bundle = 'article',
    ): EntityInterface {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($entityTypeId);
        $entity->method('id')->willReturn($id);
        $entity->method('label')->willReturn($label);
        $entity->method('bundle')->willReturn($bundle);
        $entity->method('toArray')->willReturn([
            'id' => $id,
            'type' => $entityTypeId,
            'label' => $label,
            'bundle' => $bundle,
        ]);

        return $entity;
    }
}
