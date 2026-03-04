<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives;

#[CoversClass(DiscoveryCachePrimitives::class)]
final class DiscoveryCachePrimitivesTest extends TestCase
{
    #[Test]
    public function keyIsDeterministicForEquivalentOptionOrdering(): void
    {
        $primitives = new DiscoveryCachePrimitives();

        $a = $primitives->buildKey('hub', 'node', '1', [
            'status' => 'published',
            'relationship_types' => ['related', 'supports'],
            'filters' => ['direction' => 'both', 'at' => 123],
        ]);

        $b = $primitives->buildKey('hub', 'node', '1', [
            'filters' => ['at' => 123, 'direction' => 'both'],
            'relationship_types' => ['related', 'supports'],
            'status' => 'published',
        ]);

        $this->assertSame($a, $b);
        $this->assertStringStartsWith('discovery:', $a);
    }

    #[Test]
    public function tagBuilderIncludesSourceAndRelatedEntitiesAcrossDiscoveryShapes(): void
    {
        $primitives = new DiscoveryCachePrimitives();

        $payload = [
            'data' => [
                'source' => ['type' => 'node', 'id' => '1'],
                'items' => [
                    ['related_entity_type' => 'node', 'related_entity_id' => '2'],
                ],
                'clusters' => [
                    ['related_entities' => [
                        ['type' => 'node', 'id' => '3'],
                    ]],
                ],
                'browse' => [
                    'outbound' => [
                        ['related_entity_type' => 'relationship', 'related_entity_id' => '9'],
                    ],
                    'inbound' => [],
                ],
            ],
            'meta' => [
                'surface' => 'discovery_api',
                'filters' => ['status' => 'published', 'direction' => 'both'],
            ],
        ];

        $tags = $primitives->buildTags($payload);

        $this->assertContains('discovery', $tags);
        $this->assertContains('discovery:entity:node:1', $tags);
        $this->assertContains('discovery:entity:node:2', $tags);
        $this->assertContains('discovery:entity:node:3', $tags);
        $this->assertContains('discovery:entity:relationship:9', $tags);
        $this->assertContains('discovery:status:published', $tags);
        $this->assertContains('discovery:direction:both', $tags);
    }

    #[Test]
    public function contractMetaIsAppliedWithStableDefaults(): void
    {
        $primitives = new DiscoveryCachePrimitives();

        $payload = $primitives->withContractMeta(['data' => ['items' => []]]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('discovery_api', $payload['meta']['surface']);
    }
}
