<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;

#[CoversClass(McpController::class)]
final class McpControllerTest extends TestCase
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
        $this->assertContains('search_teachings', $toolNames);
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
        $this->assertCount(12, $response['result']['tools']);
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

    #[Test]
    public function traverseRelationshipsReturnsInvalidParamsWhenRequiredArgumentsAreMissing(): void
    {
        $controller = $this->createController();

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 17,
            'method' => 'tools/call',
            'params' => ['name' => 'traverse_relationships', 'arguments' => []],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('requires non-empty "type" and "id"', $response['error']['message']);
    }

    #[Test]
    public function knowledgeGraphToolReturnsInvalidParamsWhenRequiredArgumentsAreMissing(): void
    {
        $controller = $this->createController();

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 18,
            'method' => 'tools/call',
            'params' => ['name' => 'get_knowledge_graph', 'arguments' => []],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('requires non-empty "type" and "id"', $response['error']['message']);
    }

    #[Test]
    public function traverseRelationshipsRejectsUnknownEntityType(): void
    {
        $controller = $this->createController();

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 181,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'unknown', 'id' => 1],
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('Unknown traversal entity type', $response['error']['message']);
    }

    #[Test]
    public function traversalToolsReturnExecutionErrorWhenSourceEntityIsHidden(): void
    {
        $controller = $this->createTraversalController(sourceStatus: 0, relatedStatus: 1);

        $traverse = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 182,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);
        $this->assertSame(-32000, $traverse['error']['code']);
        $this->assertStringContainsString('source entity is not visible', $traverse['error']['message']);

        $graph = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 183,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_knowledge_graph',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);
        $this->assertSame(-32000, $graph['error']['code']);
        $this->assertStringContainsString('source entity is not visible', $graph['error']['message']);
    }

    #[Test]
    public function traverseRelationshipsFiltersHiddenRelatedEntities(): void
    {
        $controller = $this->createTraversalController(sourceStatus: 1, relatedStatus: 0);

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 184,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame(0, $payload['meta']['count']);
        $this->assertSame([], $payload['data']);
    }

    #[Test]
    public function editorialPublishTransitionsNodeWhenAuthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'publish article content'],
            roles: ['reviewer'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 19,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_publish',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('published', $payload['data']['workflow_state']);
        $this->assertSame(1, $payload['data']['status']);
        $this->assertSame('publish', $payload['data']['workflow_last_transition']['id']);
    }

    #[Test]
    public function editorialArchiveTransitionsNodeWhenAuthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'archive article content'],
            roles: ['editor'],
            workflowState: 'published',
            status: 1,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_archive',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('archived', $payload['data']['workflow_state']);
        $this->assertSame(0, $payload['data']['status']);
        $this->assertSame('archive', $payload['data']['workflow_last_transition']['id']);
    }

    #[Test]
    public function editorialTransitionReturnsExecutionErrorWhenUnauthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_transition',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'published'],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('publish article content', $response['error']['message']);
    }

    #[Test]
    public function editorialValidateReturnsDeterministicViolationsWithoutMutatingState(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_validate',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'published'],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertFalse($payload['data']['is_valid']);
        $this->assertSame('review', $payload['data']['workflow_state']);
        $this->assertSame('published', $payload['data']['requested_state']);
        $this->assertNotEmpty($payload['data']['violations']);
    }

    #[Test]
    public function editorialTransitionRequiresKnownTargetState(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'publish article content'],
            roles: ['reviewer'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_transition',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'inbox'],
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('Unknown editorial workflow state', $response['error']['message']);
    }

    #[Test]
    public function aiDiscoverRequiresNonEmptyQuery(): void
    {
        $controller = $this->createController();
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 24,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => ['query' => '  '],
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('non-empty "query"', $response['error']['message']);
    }

    #[Test]
    public function aiDiscoverReturnsDeterministicRecommendationContract(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'published',
            status: 1,
        );
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 25,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'Editorial',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => 1,
                ],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('ai_discover', $payload['meta']['tool']);
        $this->assertSame(1, $payload['meta']['count']);
        $this->assertCount(1, $payload['data']['recommendations']);
        $this->assertSame('published_only', $payload['data']['recommendations'][0]['explanation']['visibility_contract']);
        $this->assertSame('node', $payload['data']['graph_context']['source']['type']);
        $this->assertSame('1', $payload['data']['graph_context']['source']['id']);
    }

    #[Test]
    public function aiDiscoverRejectsNonPublicAnchorEntity(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'draft',
            status: 0,
        );
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 26,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'editorial',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => 1,
                ],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('not public', $response['error']['message']);
    }

    #[Test]
    public function searchEntitiesAndLegacyAliasShareStableContractMetadata(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'published',
            status: 1,
        );

        $canonical = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 27,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_entities',
                'arguments' => ['query' => 'Editorial', 'type' => 'node', 'limit' => 5],
            ],
        ]);
        $canonicalPayload = $this->decodeToolPayload($canonical);
        $this->assertSame('v1.0', $canonicalPayload['meta']['contract_version']);
        $this->assertSame('stable', $canonicalPayload['meta']['contract_stability']);
        $this->assertSame('search_entities', $canonicalPayload['meta']['tool']);
        $this->assertSame('search_entities', $canonicalPayload['meta']['tool_invoked']);

        $legacy = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 28,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_teachings',
                'arguments' => ['query' => 'Editorial', 'type' => 'node', 'limit' => 5],
            ],
        ]);
        $legacyPayload = $this->decodeToolPayload($legacy);
        $this->assertSame('v1.0', $legacyPayload['meta']['contract_version']);
        $this->assertSame('search_entities', $legacyPayload['meta']['tool']);
        $this->assertSame('search_teachings', $legacyPayload['meta']['tool_invoked']);
        $this->assertSame('search_teachings', $legacyPayload['meta']['deprecated_alias']);
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
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    private function createEditorialController(
        array $permissions,
        array $roles,
        string $workflowState,
        int $status,
    ): McpController {
        $nodeDefinition = new EntityType(
            id: 'node',
            label: 'Node',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [],
        );

        $nodeStorage = new InMemoryEntityStorage('node');
        $entity = $nodeStorage->create([
            'type' => 'article',
            'title' => 'Editorial Node',
            'status' => $status,
            'workflow_state' => $workflowState,
        ]);
        $nodeStorage->save($entity);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $entityTypeId): bool => $entityTypeId === 'node');
        $manager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeStorage
            : throw new \RuntimeException('Unknown storage'));
        $manager->method('getDefinition')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeDefinition
            : throw new \RuntimeException('Unknown definition'));
        $manager->method('getDefinitions')->willReturn(['node' => $nodeDefinition]);

        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $account = new TestMcpAccount(
            userId: 5,
            permissions: $permissions,
            roles: $roles,
            authenticated: true,
        );
        $access = new EntityAccessHandler([new TestNodeUpdatePolicy()]);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $access,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );
    }

    private function createTraversalController(int $sourceStatus, int $relatedStatus): McpController
    {
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
        $account = new TestMcpAccount();
        $access = new EntityAccessHandler([new TestNodeVisibilityPolicy(), new TestRelationshipViewPolicy()]);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $access,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
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

final class TestMcpAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int|string $userId = 0,
        private readonly array $permissions = [],
        private readonly array $roles = ['anonymous'],
        private readonly bool $authenticated = false,
    ) {}

    public function id(): int|string { return $this->userId; }
    public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
    public function getRoles(): array { return $this->roles; }
    public function isAuthenticated(): bool { return $this->authenticated; }
}

final class TestNodeUpdatePolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'update') {
            return $account->hasPermission('edit any article content')
                ? AccessResult::allowed('Update access granted.')
                : AccessResult::neutral('Update access denied.');
        }

        return AccessResult::allowed('Allowed for test operation.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Not used.');
    }
}

final class TestNodeVisibilityPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral('Not used.');
        }

        return (int) ($entity->toArray()['status'] ?? 0) === 1
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Not used.');
    }
}

final class TestRelationshipViewPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral('Not used.');
        }

        return AccessResult::allowed('Allowed for traversal.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Not used.');
    }
}
