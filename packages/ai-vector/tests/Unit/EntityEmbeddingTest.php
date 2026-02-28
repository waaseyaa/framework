<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\EntityEmbedding;
use PHPUnit\Framework\TestCase;

final class EntityEmbeddingTest extends TestCase
{
    public function testConstruction(): void
    {
        $vector = [0.1, 0.2, 0.3];
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 42,
            vector: $vector,
            metadata: ['title' => 'Test Article'],
            createdAt: 1700000000,
        );

        $this->assertSame('node', $embedding->entityTypeId);
        $this->assertSame(42, $embedding->entityId);
        $this->assertSame($vector, $embedding->vector);
        $this->assertSame(['title' => 'Test Article'], $embedding->metadata);
        $this->assertSame(1700000000, $embedding->createdAt);
    }

    public function testConstructionWithDefaults(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'taxonomy_term',
            entityId: 'abc-123',
            vector: [0.5, 0.6],
        );

        $this->assertSame('taxonomy_term', $embedding->entityTypeId);
        $this->assertSame('abc-123', $embedding->entityId);
        $this->assertSame([0.5, 0.6], $embedding->vector);
        $this->assertSame([], $embedding->metadata);
        $this->assertSame(0, $embedding->createdAt);
    }

    public function testStringEntityId(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'user',
            entityId: 'uuid-string',
            vector: [1.0],
        );

        $this->assertSame('uuid-string', $embedding->entityId);
    }
}
