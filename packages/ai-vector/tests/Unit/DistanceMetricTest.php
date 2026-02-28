<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Tests\Unit;

use Aurora\AI\Vector\DistanceMetric;
use PHPUnit\Framework\TestCase;

final class DistanceMetricTest extends TestCase
{
    public function testCosineValue(): void
    {
        $this->assertSame('cosine', DistanceMetric::COSINE->value);
    }

    public function testEuclideanValue(): void
    {
        $this->assertSame('euclidean', DistanceMetric::EUCLIDEAN->value);
    }

    public function testDotProductValue(): void
    {
        $this->assertSame('dot_product', DistanceMetric::DOT_PRODUCT->value);
    }

    public function testFromString(): void
    {
        $this->assertSame(DistanceMetric::COSINE, DistanceMetric::from('cosine'));
        $this->assertSame(DistanceMetric::EUCLIDEAN, DistanceMetric::from('euclidean'));
        $this->assertSame(DistanceMetric::DOT_PRODUCT, DistanceMetric::from('dot_product'));
    }

    public function testAllCases(): void
    {
        $cases = DistanceMetric::cases();
        $this->assertCount(3, $cases);
    }
}
