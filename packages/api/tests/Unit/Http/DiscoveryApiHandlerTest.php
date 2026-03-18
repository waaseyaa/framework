<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(DiscoveryApiHandler::class)]
final class DiscoveryApiHandlerTest extends TestCase
{
    #[Test]
    public function parses_relationship_types_from_comma_separated_query_string(): void
    {
        $handler = $this->createHandler();
        $types = $handler->parseRelationshipTypesQuery('references, influences, ,references');
        $this->assertSame(['references', 'influences', 'references'], $types);
    }

    #[Test]
    public function parses_relationship_types_from_array_query_value(): void
    {
        $handler = $this->createHandler();
        $types = $handler->parseRelationshipTypesQuery(['references', 'influences', 'references', '', 123]);
        $this->assertSame(['references', 'influences'], $types);
    }

    #[Test]
    public function parses_relationship_types_returns_empty_for_null(): void
    {
        $handler = $this->createHandler();
        $this->assertSame([], $handler->parseRelationshipTypesQuery(null));
    }

    #[Test]
    public function discovery_cache_key_is_deterministic_for_equivalent_option_order(): void
    {
        $handler = $this->createHandler();
        $keyA = $handler->buildDiscoveryCacheKey('timeline', 'node', '1', [
            'status' => 'published',
            'direction' => 'both',
            'from' => 100,
            'to' => 200,
            'relationship_types' => ['references', 'influences'],
        ]);
        $keyB = $handler->buildDiscoveryCacheKey('timeline', 'node', '1', [
            'relationship_types' => ['references', 'influences'],
            'to' => 200,
            'from' => 100,
            'direction' => 'both',
            'status' => 'published',
        ]);

        $this->assertSame($keyA, $keyB);
    }

    #[Test]
    public function discovery_cache_key_changes_when_filter_values_change(): void
    {
        $handler = $this->createHandler();
        $keyA = $handler->buildDiscoveryCacheKey('hub', 'node', '1', ['status' => 'published', 'limit' => 10]);
        $keyB = $handler->buildDiscoveryCacheKey('hub', 'node', '1', ['status' => 'published', 'limit' => 20]);
        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function discovery_payload_contract_meta_is_added_when_missing(): void
    {
        $handler = $this->createHandler();
        $payload = $handler->withDiscoveryContractMeta(['data' => ['source' => ['type' => 'node', 'id' => '1']]]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('discovery_api', $payload['meta']['surface']);
    }

    #[Test]
    public function discovery_payload_contract_meta_preserves_existing_surface(): void
    {
        $handler = $this->createHandler();
        $payload = $handler->withDiscoveryContractMeta([
            'data' => [],
            'meta' => ['surface' => 'custom_surface', 'count' => 3],
        ]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('custom_surface', $payload['meta']['surface']);
        $this->assertSame(3, $payload['meta']['count']);
    }

    #[Test]
    public function discovery_cache_tags_include_surface_entity_and_filters(): void
    {
        $handler = $this->createHandler();
        $tags = $handler->buildDiscoveryCacheTags([
            'data' => [
                'data' => ['source' => ['type' => 'node', 'id' => '42']],
            ],
            'meta' => [
                'surface' => 'discovery_api',
                'filters' => ['status' => 'published', 'direction' => 'both'],
            ],
        ]);

        $this->assertContains('discovery', $tags);
        $this->assertContains('discovery:contract:v1.0', $tags);
        $this->assertContains('discovery:surface:discovery_api', $tags);
        $this->assertContains('discovery:entity:node', $tags);
        $this->assertContains('discovery:entity:node:42', $tags);
        $this->assertContains('discovery:status:published', $tags);
        $this->assertContains('discovery:direction:both', $tags);
    }

    #[Test]
    public function discovery_cache_tags_include_related_entities(): void
    {
        $handler = $this->createHandler();
        $tags = $handler->buildDiscoveryCacheTags([
            'data' => [
                'source' => ['type' => 'node', 'id' => '1'],
                'items' => [
                    ['related_entity_type' => 'node', 'related_entity_id' => '2'],
                ],
                'clusters' => [[
                    'related_entities' => [
                        ['type' => 'node', 'id' => '3'],
                    ],
                ]],
            ],
            'meta' => [
                'surface' => 'discovery_api',
                'filters' => ['status' => 'published', 'direction' => 'both'],
            ],
        ]);

        $this->assertContains('discovery:entity:node:1', $tags);
        $this->assertContains('discovery:entity:node:2', $tags);
        $this->assertContains('discovery:entity:node:3', $tags);
    }

    #[Test]
    public function get_discovery_cached_response_returns_null_for_authenticated_users(): void
    {
        $handler = $this->createHandler();
        $account = new \Waaseyaa\User\DevAdminAccount();
        $this->assertNull($handler->getDiscoveryCachedResponse('some_key', $account));
    }

    #[Test]
    public function get_discovery_cached_response_returns_null_when_no_cache(): void
    {
        $handler = new DiscoveryApiHandler(
            new EntityTypeManager(new EventDispatcher()),
            \Waaseyaa\Database\DBALDatabase::createSqlite(),
            null,
        );
        $this->assertNull($handler->getDiscoveryCachedResponse('some_key', new AnonymousUser()));
    }

    #[Test]
    public function normalize_for_cache_key_sorts_associative_arrays(): void
    {
        $handler = $this->createHandler();
        $a = $handler->normalizeForCacheKey(['b' => 2, 'a' => 1]);
        $b = $handler->normalizeForCacheKey(['a' => 1, 'b' => 2]);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function normalize_for_cache_key_preserves_list_order(): void
    {
        $handler = $this->createHandler();
        $result = $handler->normalizeForCacheKey([3, 1, 2]);
        $this->assertSame([3, 1, 2], $result);
    }

    private function createHandler(): DiscoveryApiHandler
    {
        return new DiscoveryApiHandler(
            new EntityTypeManager(new EventDispatcher()),
            \Waaseyaa\Database\DBALDatabase::createSqlite(),
        );
    }
}
