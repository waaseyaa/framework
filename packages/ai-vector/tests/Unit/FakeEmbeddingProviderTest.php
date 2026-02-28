<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\Testing\FakeEmbeddingProvider;
use PHPUnit\Framework\TestCase;

final class FakeEmbeddingProviderTest extends TestCase
{
    public function testSameTextProducesSameVector(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 64);

        $vector1 = $provider->embed('hello world');
        $vector2 = $provider->embed('hello world');

        $this->assertSame($vector1, $vector2);
    }

    public function testDifferentTextProducesDifferentVectors(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 64);

        $vector1 = $provider->embed('hello world');
        $vector2 = $provider->embed('goodbye world');

        $this->assertNotSame($vector1, $vector2);
    }

    public function testCorrectDimensions(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 128);

        $vector = $provider->embed('test text');

        $this->assertCount(128, $vector);
    }

    public function testCustomDimensions(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 256);

        $this->assertSame(256, $provider->getDimensions());
        $this->assertCount(256, $provider->embed('test'));
    }

    public function testDefaultDimensions(): void
    {
        $provider = new FakeEmbeddingProvider();

        $this->assertSame(128, $provider->getDimensions());
        $this->assertCount(128, $provider->embed('test'));
    }

    public function testBatchEmbedding(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 32);

        $texts = ['first', 'second', 'third'];
        $results = $provider->embedBatch($texts);

        $this->assertCount(3, $results);

        // Each result should have the right dimensions.
        foreach ($results as $vector) {
            $this->assertCount(32, $vector);
        }

        // Batch results should match individual calls.
        $this->assertSame($provider->embed('first'), $results[0]);
        $this->assertSame($provider->embed('second'), $results[1]);
        $this->assertSame($provider->embed('third'), $results[2]);
    }

    public function testVectorsAreNormalized(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 128);

        $vector = $provider->embed('test normalization');

        // Calculate magnitude.
        $magnitude = 0.0;
        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }
        $magnitude = sqrt($magnitude);

        // Magnitude should be approximately 1.0.
        $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
    }

    public function testAllValuesAreFloats(): void
    {
        $provider = new FakeEmbeddingProvider(dimensions: 16);

        $vector = $provider->embed('type check');

        foreach ($vector as $value) {
            $this->assertIsFloat($value);
        }
    }
}
