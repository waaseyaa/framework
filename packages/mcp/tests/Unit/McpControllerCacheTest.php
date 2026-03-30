<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\PermissionAwareNodeVisibilityPolicy;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestMcpAccount;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestRelationshipViewPolicy;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TrackingMemoryCacheBackend;

#[CoversClass(McpController::class)]
final class McpControllerCacheTest extends TestCase
{
    #[Test]
    public function mcpReadCacheReusesEquivalentTraversalResponses(): void
    {
        $cache = new TrackingMemoryCacheBackend();
        $controller = $this->createTraversalControllerWithCache(
            sourceStatus: 1,
            relatedStatus: 1,
            permissions: [],
            roles: ['anonymous'],
            authenticated: false,
            userId: 0,
            cache: $cache,
        );

        $first = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 29,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);
        $second = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $this->assertSame(1, $cache->setCalls);
        $this->assertGreaterThanOrEqual(2, $cache->getCalls);
        $this->assertSame(
            $this->decodeToolPayload($first),
            $this->decodeToolPayload($second),
        );
    }

    #[Test]
    public function mcpReadCacheKeysArePermissionScopedAcrossAccounts(): void
    {
        $cache = new TrackingMemoryCacheBackend();

        $privileged = $this->createTraversalControllerWithCache(
            sourceStatus: 1,
            relatedStatus: 0,
            permissions: ['view unpublished content'],
            roles: ['editor'],
            authenticated: true,
            userId: 101,
            cache: $cache,
        );
        $restricted = $this->createTraversalControllerWithCache(
            sourceStatus: 1,
            relatedStatus: 0,
            permissions: [],
            roles: ['anonymous'],
            authenticated: false,
            userId: 0,
            cache: $cache,
        );

        $privilegedPayload = $this->decodeToolPayload($privileged->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 31,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_related_entities',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]));
        $restrictedPayload = $this->decodeToolPayload($restricted->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 32,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_related_entities',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]));

        $this->assertSame(1, $privilegedPayload['meta']['count']);
        $this->assertSame(0, $restrictedPayload['meta']['count']);
        $this->assertSame(2, $cache->setCalls);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    private function createTraversalControllerWithCache(
        int $sourceStatus,
        int $relatedStatus,
        array $permissions,
        array $roles,
        bool $authenticated,
        int|string $userId,
        CacheBackendInterface $cache,
    ): McpController {
        $nodeDefinition = new EntityType(
            id: 'node',
            label: 'Node',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [],
        );
        $relationshipDefinition = new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'relationship_type'],
            fieldDefinitions: [],
        );

        $nodeStorage = new InMemoryEntityStorage('node');
        $source = $nodeStorage->create([
            'type' => 'article',
            'title' => 'Source Node',
            'status' => $sourceStatus,
            'workflow_state' => $sourceStatus === 1 ? 'published' : 'draft',
        ]);
        $nodeStorage->save($source);

        $related = $nodeStorage->create([
            'type' => 'article',
            'title' => 'Related Node',
            'status' => $relatedStatus,
            'workflow_state' => $relatedStatus === 1 ? 'published' : 'draft',
        ]);
        $nodeStorage->save($related);

        $relationshipStorage = new InMemoryEntityStorage('relationship');
        $relationship = $relationshipStorage->create([
            'relationship_type' => 'related',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $source->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $related->id(),
            'status' => 1,
        ]);
        $relationshipStorage->save($relationship);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $entityTypeId): bool => in_array($entityTypeId, ['node', 'relationship'], true));
        $manager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId) => match ($entityTypeId) {
            'node' => $nodeStorage,
            'relationship' => $relationshipStorage,
            default => throw new \RuntimeException('Unknown storage'),
        });
        $manager->method('getDefinition')->willReturnCallback(static fn(string $entityTypeId) => match ($entityTypeId) {
            'node' => $nodeDefinition,
            'relationship' => $relationshipDefinition,
            default => throw new \RuntimeException('Unknown definition'),
        });
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeDefinition,
            'relationship' => $relationshipDefinition,
        ]);

        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $account = new TestMcpAccount(
            userId: $userId,
            permissions: $permissions,
            roles: $roles,
            authenticated: $authenticated,
        );
        $access = new EntityAccessHandler([new PermissionAwareNodeVisibilityPolicy(), new TestRelationshipViewPolicy()]);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $access,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            readCache: $cache,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decodeToolPayload(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertArrayHasKey(0, $response['result']['content']);
        $this->assertArrayHasKey('text', $response['result']['content'][0]);

        return json_decode((string) $response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
