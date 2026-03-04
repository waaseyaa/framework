<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityType;
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
        $toolNames = array_map(static fn(array $tool): string => $tool['name'], $manifest['tools']);
        $this->assertContains('search_teachings', $toolNames);
        $this->assertContains('get_entity', $toolNames);
        $this->assertContains('list_entity_types', $toolNames);
        $this->assertContains('traverse_relationships', $toolNames);
        $this->assertContains('get_related_entities', $toolNames);
        $this->assertContains('get_knowledge_graph', $toolNames);
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
        $this->assertCount(6, $response['result']['tools']);
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
}

final class TestMcpAccount implements AccountInterface
{
    public function id(): int|string { return 0; }
    public function hasPermission(string $permission): bool { return false; }
    public function getRoles(): array { return ['anonymous']; }
    public function isAuthenticated(): bool { return false; }
}
