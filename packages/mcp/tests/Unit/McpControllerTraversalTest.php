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
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestMcpAccount;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestNodeVisibilityPolicy;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestRelationshipViewPolicy;

#[CoversClass(McpController::class)]
final class McpControllerTraversalTest extends TestCase
{
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
