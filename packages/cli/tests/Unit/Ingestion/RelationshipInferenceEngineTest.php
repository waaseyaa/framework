<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\RelationshipInferenceEngine;

#[CoversClass(RelationshipInferenceEngine::class)]
final class RelationshipInferenceEngineTest extends TestCase
{
    #[Test]
    public function it_infers_review_safe_relationships_deterministically(): void
    {
        $engine = new RelationshipInferenceEngine();
        $relationships = $engine->infer(
            nodes: [
                'water_story' => [
                    'title' => 'Water Story',
                    'body' => 'Water stewardship knowledge supports seasonal ceremony and memory.',
                ],
                'seasonal_memory' => [
                    'title' => 'Seasonal Memory',
                    'body' => 'Seasonal ceremony memory teachings support community stewardship.',
                ],
            ],
            existingRelationships: [],
        );

        $this->assertCount(1, $relationships);
        $this->assertSame('seasonal_memory_to_water_story_related_to_inferred', $relationships[0]['key']);
        $this->assertSame(0, $relationships[0]['status']);
        $this->assertSame('needs_review', $relationships[0]['inference_review_state']);
        $this->assertSame('text_overlap_v1', $relationships[0]['inference_source']);
        $this->assertGreaterThan(0.0, (float) $relationships[0]['inference_confidence']);
    }

    #[Test]
    public function it_does_not_infer_when_explicit_relationship_already_exists_for_pair(): void
    {
        $engine = new RelationshipInferenceEngine();
        $relationships = $engine->infer(
            nodes: [
                'a' => ['title' => 'A', 'body' => 'Shared seasonal memory knowledge.'],
                'b' => ['title' => 'B', 'body' => 'Shared seasonal memory ceremony.'],
            ],
            existingRelationships: [
                [
                    'key' => 'a_to_b_supports',
                    'from' => 'a',
                    'to' => 'b',
                    'relationship_type' => 'supports',
                ],
            ],
        );

        $this->assertSame([], $relationships);
    }
}
