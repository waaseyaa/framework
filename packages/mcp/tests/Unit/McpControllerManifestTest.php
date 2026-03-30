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
final class McpControllerManifestTest extends TestCase
{
    #[Test]
    public function manifestListsRequiredTools(): void
    {
        $controller = $this->createController();
        $manifest = $controller->manifest();

        $this->assertSame('2024-11-05', $manifest['protocolVersion']);
        $this->assertSame('v1.0', $manifest['server']['version']);
        $toolNames = array_map(static fn(array $tool): string => $tool['name'], $manifest['tools']);
        $this->assertContains('search_entities', $toolNames);
        $this->assertContains('ai_discover', $toolNames);
        $this->assertContains('get_entity', $toolNames);
        $this->assertContains('list_entity_types', $toolNames);
        $this->assertContains('traverse_relationships', $toolNames);
        $this->assertContains('get_related_entities', $toolNames);
        $this->assertContains('get_knowledge_graph', $toolNames);
        $this->assertContains('editorial_transition', $toolNames);
        $this->assertContains('editorial_validate', $toolNames);
        $this->assertContains('editorial_publish', $toolNames);
        $this->assertContains('editorial_archive', $toolNames);
    }

    #[Test]
    public function toolsListReturnsManifestTools(): void
    {
        $controller = $this->createController();
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertCount(11, $response['result']['tools']);
    }

    #[Test]
    public function toolsIntrospectReturnsDeterministicContractAndCacheContext(): void
    {
        $cache = new TrackingMemoryCacheBackend();
        $controller = $this->createTraversalControllerWithCache(
            sourceStatus: 1,
            relatedStatus: 1,
            permissions: ['view unpublished content'],
            roles: ['editor', 'authenticated'],
            authenticated: true,
            userId: 101,
            cache: $cache,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/introspect',
            'params' => [
                'name' => 'get_related_entities',
            ],
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(2, $response['id']);
        $this->assertSame('get_related_entities', $response['result']['tool']['requested']);
        $this->assertSame('get_related_entities', $response['result']['tool']['canonical']);
        $this->assertFalse($response['result']['tool']['is_alias']);
        $this->assertSame('v1.0', $response['result']['contract']['contract_version']);
        $this->assertSame('stable', $response['result']['contract']['contract_stability']);
        $this->assertTrue($response['result']['cache']['read_cacheable']);
        $this->assertTrue($response['result']['cache']['read_cache_enabled']);
        $this->assertSame('authenticated', $response['result']['cache']['scope']);
        $this->assertSame('101', $response['result']['cache']['account_context']['account_id']);
        $this->assertContains('entity:view', $response['result']['permissions']['boundaries']);
        $this->assertContains('relationship:view', $response['result']['permissions']['boundaries']);
        $this->assertContains('resolver:toolGetRelatedEntities', $response['result']['diagnostics']['execution_path']);
    }

    #[Test]
    public function toolsIntrospectReturnsInvalidParamsForUnknownTool(): void
    {
        $controller = $this->createController();
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/introspect',
            'params' => [
                'name' => 'nope',
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('Unknown tool: nope', $response['error']['message']);
    }

    #[Test]
    public function toolsIntrospectIncludesRegisteredExtensionHooksForApplicableTool(): void
    {
        $controller = $this->createControllerWithExtensions([
            [
                'id' => 'external_discovery_pack',
                'label' => 'External Discovery Pack',
                'tools' => ['ai_discover', 'search_entities'],
                'hooks' => ['before_tool_call', 'after_tool_result_meta'],
            ],
            [
                'id' => 'workflow_only_pack',
                'label' => 'Workflow Pack',
                'tools' => ['editorial_transition'],
                'hooks' => ['before_tool_call'],
            ],
        ]);

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/introspect',
            'params' => [
                'name' => 'ai_discover',
            ],
        ]);

        $this->assertSame(1, $response['result']['extensions']['count']);
        $this->assertSame('external_discovery_pack', $response['result']['extensions']['registered'][0]['id']);
        $this->assertContains('extensions:before_tool_call', $response['result']['diagnostics']['execution_path']);
        $this->assertContains('extensions:after_tool_result_meta', $response['result']['diagnostics']['execution_path']);
    }

    #[Test]
    public function toolsCallContractMetaRemainsStableWhenExtensionsAreRegistered(): void
    {
        $controller = $this->createControllerWithExtensions([
            [
                'id' => 'external_discovery_pack',
                'tools' => ['list_entity_types'],
                'hooks' => ['before_tool_call'],
            ],
        ]);

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_entity_types',
                'arguments' => [],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('list_entity_types', $payload['meta']['tool']);
        $this->assertSame('list_entity_types', $payload['meta']['tool_invoked']);
        $this->assertArrayNotHasKey('extensions', $payload['meta']);
    }

    #[Test]
    public function listEntityTypesToolReturnsDefinitions(): void
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $definition]);
        $manager->method('getDefinition')->willReturn($definition);

        $serializer = new ResourceSerializer($manager);
        $storage = $this->createMock(EmbeddingStorageInterface::class);

        $controller = new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: new EntityAccessHandler([]),
            account: new TestMcpAccount(),
            embeddingStorage: $storage,
            embeddingProvider: null,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => 'list_entity_types', 'arguments' => []],
        ]);

        $text = $response['result']['content'][0]['text'];
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('node', $decoded['data'][0]['id']);
        $this->assertSame('Node', $decoded['data'][0]['label']);
    }

    private function createController(): McpController
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);
        $manager->method('getDefinition')->willThrowException(new \RuntimeException('not used'));

        $serializer = new ResourceSerializer($manager);
        $storage = $this->createMock(EmbeddingStorageInterface::class);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: new EntityAccessHandler([]),
            account: new TestMcpAccount(),
            embeddingStorage: $storage,
            embeddingProvider: null,
        );
    }

    /**
     * @param list<array<string, mixed>> $extensions
     */
    private function createControllerWithExtensions(array $extensions): McpController
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);
        $manager->method('getDefinition')->willThrowException(new \RuntimeException('not used'));

        $serializer = new ResourceSerializer($manager);
        $storage = $this->createMock(EmbeddingStorageInterface::class);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: new EntityAccessHandler([]),
            account: new TestMcpAccount(),
            embeddingStorage: $storage,
            embeddingProvider: null,
            extensionRegistrations: $extensions,
        );
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
