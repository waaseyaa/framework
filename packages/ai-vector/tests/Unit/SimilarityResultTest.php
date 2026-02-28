<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\EntityEmbedding;
use Aurora\AI\Vector\SimilarityResult;
use PHPUnit\Framework\TestCase;

final class SimilarityResultTest extends TestCase
{
    public function testConstruction(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.1, 0.2, 0.3],
        );

        $result = new SimilarityResult(
            embedding: $embedding,
            score: 0.95,
        );

        $this->assertSame($embedding, $result->embedding);
        $this->assertSame(0.95, $result->score);
    }

    public function testZeroScore(): void
    {
        $embedding = new EntityEmbedding(
            entityTypeId: 'node',
            entityId: 1,
            vector: [0.0],
        );

        $result = new SimilarityResult(
            embedding: $embedding,
            score: 0.0,
        );

        $this->assertSame(0.0, $result->score);
    }
}
