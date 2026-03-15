<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase8;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;
use Waaseyaa\AI\Schema\SchemaRegistry;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Schema generation + MCP tool execution with real entity storage.
 *
 * Exercises: waaseyaa/ai-schema (EntityJsonSchemaGenerator, McpToolGenerator,
 * McpToolExecutor, SchemaRegistry) with waaseyaa/entity (EntityTypeManager,
 * EntityType) using in-memory storage.
 */
#[CoversNothing]
final class SchemaToolIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $nodeStorage;
    private EntityJsonSchemaGenerator $schemaGenerator;
    private McpToolGenerator $toolGenerator;
    private McpToolExecutor $toolExecutor;
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        $this->nodeStorage = new InMemoryEntityStorage('node');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->nodeStorage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        ));

        $this->schemaGenerator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $this->toolGenerator = new McpToolGenerator($this->entityTypeManager);
        $this->toolExecutor = new McpToolExecutor($this->entityTypeManager);
        $this->registry = new SchemaRegistry($this->schemaGenerator, $this->toolGenerator);
    }

    #[Test]
    public function generateJsonSchemaHasCorrectProperties(): void
    {
        $schema = $this->schemaGenerator->generate('node');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('Node', $schema['title']);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('nid', $schema['properties']);
        $this->assertArrayHasKey('uuid', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('type', $schema['properties']);
        $this->assertContains('nid', $schema['required']);
        $this->assertContains('uuid', $schema['required']);
        $this->assertContains('title', $schema['required']);
        $this->assertContains('type', $schema['required']);
        $this->assertTrue($schema['additionalProperties']);
    }

    #[Test]
    public function generateMcpToolsProducesFiveToolsForNode(): void
    {
        $tools = $this->toolGenerator->generateForEntityType('node');

        $this->assertCount(5, $tools);

        $toolNames = array_map(fn(McpToolDefinition $t) => $t->name, $tools);
        $this->assertContains('create_node', $toolNames);
        $this->assertContains('read_node', $toolNames);
        $this->assertContains('update_node', $toolNames);
        $this->assertContains('delete_node', $toolNames);
        $this->assertContains('query_node', $toolNames);

        // Verify each tool has expected structure.
        foreach ($tools as $tool) {
            $this->assertInstanceOf(McpToolDefinition::class, $tool);
            $this->assertNotEmpty($tool->name);
            $this->assertNotEmpty($tool->description);
            $this->assertNotEmpty($tool->inputSchema);

            $array = $tool->toArray();
            $this->assertArrayHasKey('name', $array);
            $this->assertArrayHasKey('description', $array);
            $this->assertArrayHasKey('inputSchema', $array);
        }
    }

    #[Test]
    public function executeCreateNodeToolCreatesEntityInStorage(): void
    {
        $result = $this->toolExecutor->execute('create_node', [
            'attributes' => [
                'title' => 'My First Article',
                'type' => 'article',
            ],
        ]);

        $this->assertArrayNotHasKey('isError', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);

        $data = json_decode($result['content'][0]['text'], true);
        $this->assertSame('create', $data['operation']);
        $this->assertSame('node', $data['entity_type']);
        $this->assertNotNull($data['id']);

        // Verify entity exists in storage.
        $entity = $this->nodeStorage->load($data['id']);
        $this->assertNotNull($entity);
        $this->assertSame('My First Article', $entity->label());
    }

    #[Test]
    public function executeReadNodeToolReturnsEntityData(): void
    {
        // Create a node first.
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Readable Node', 'type' => 'page'],
        ]);

        $result = $this->toolExecutor->execute('read_node', ['id' => 1]);

        $this->assertArrayNotHasKey('isError', $result);

        $data = json_decode($result['content'][0]['text'], true);
        $this->assertSame('read', $data['operation']);
        $this->assertSame('node', $data['entity_type']);
        $this->assertSame('Readable Node', $data['data']['title']);
    }

    #[Test]
    public function executeUpdateNodeToolUpdatesEntity(): void
    {
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Original Title', 'type' => 'article'],
        ]);

        $result = $this->toolExecutor->execute('update_node', [
            'id' => 1,
            'attributes' => ['title' => 'Updated Title'],
        ]);

        $this->assertArrayNotHasKey('isError', $result);

        $data = json_decode($result['content'][0]['text'], true);
        $this->assertSame('update', $data['operation']);
        $this->assertSame('Updated Title', $data['data']['title']);

        // Verify persistence.
        $entity = $this->nodeStorage->load(1);
        $this->assertSame('Updated Title', $entity->label());
    }

    #[Test]
    public function executeDeleteNodeToolRemovesEntity(): void
    {
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'To Delete', 'type' => 'page'],
        ]);
        $this->assertNotNull($this->nodeStorage->load(1));

        $result = $this->toolExecutor->execute('delete_node', ['id' => 1]);

        $this->assertArrayNotHasKey('isError', $result);

        $data = json_decode($result['content'][0]['text'], true);
        $this->assertSame('delete', $data['operation']);

        // Verify entity is gone.
        $this->assertNull($this->nodeStorage->load(1));
    }

    #[Test]
    public function executeQueryNodeToolReturnsEntityList(): void
    {
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Node A', 'type' => 'article'],
        ]);
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Node B', 'type' => 'page'],
        ]);
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Node C', 'type' => 'article'],
        ]);

        $result = $this->toolExecutor->execute('query_node', []);

        $this->assertArrayNotHasKey('isError', $result);

        $data = json_decode($result['content'][0]['text'], true);
        $this->assertSame('query', $data['operation']);
        $this->assertSame(3, $data['count']);
        $this->assertCount(3, $data['results']);
    }

    #[Test]
    public function schemaRegistryAggregatesSchemasAndTools(): void
    {
        // Single schema.
        $schema = $this->registry->getSchema('node');
        $this->assertSame('Node', $schema['title']);

        // All schemas.
        $allSchemas = $this->registry->getAllSchemas();
        $this->assertArrayHasKey('node', $allSchemas);
        $this->assertSame('Node', $allSchemas['node']['title']);

        // All tools.
        $tools = $this->registry->getTools();
        $this->assertCount(5, $tools);

        // Get specific tool.
        $createTool = $this->registry->getTool('create_node');
        $this->assertNotNull($createTool);
        $this->assertSame('create_node', $createTool->name);

        // Non-existent tool returns null.
        $this->assertNull($this->registry->getTool('nonexistent_tool'));
    }

    #[Test]
    public function multipleEntityTypesProduceCorrectCombinedOutput(): void
    {
        // Register a second entity type.
        $userStorage = new InMemoryEntityStorage('user');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            function ($definition) use ($userStorage) {
                return match ($definition->id()) {
                    'node' => $this->nodeStorage,
                    'user' => $userStorage,
                    default => throw new \RuntimeException("Unknown: {$definition->id()}"),
                };
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $schemaGen = new EntityJsonSchemaGenerator($entityTypeManager);
        $toolGen = new McpToolGenerator($entityTypeManager);
        $registry = new SchemaRegistry($schemaGen, $toolGen);

        // Two schemas.
        $allSchemas = $registry->getAllSchemas();
        $this->assertCount(2, $allSchemas);
        $this->assertArrayHasKey('node', $allSchemas);
        $this->assertArrayHasKey('user', $allSchemas);

        // 10 tools total (5 per entity type).
        $allTools = $registry->getTools();
        $this->assertCount(10, $allTools);

        $toolNames = array_map(fn(McpToolDefinition $t) => $t->name, $allTools);
        $this->assertContains('create_node', $toolNames);
        $this->assertContains('read_node', $toolNames);
        $this->assertContains('create_user', $toolNames);
        $this->assertContains('read_user', $toolNames);
    }

    #[Test]
    public function executeToolWithUnknownEntityTypeReturnsError(): void
    {
        $result = $this->toolExecutor->execute('read_unknown', ['id' => 1]);

        $this->assertTrue($result['isError']);
        $data = json_decode($result['content'][0]['text'], true);
        $this->assertArrayHasKey('error', $data);
    }

    #[Test]
    public function executeReadNonExistentEntityReturnsError(): void
    {
        $result = $this->toolExecutor->execute('read_node', ['id' => 999]);

        $this->assertTrue($result['isError']);
        $data = json_decode($result['content'][0]['text'], true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function fullCrudLifecycleViaMcpTools(): void
    {
        // CREATE.
        $createResult = $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Lifecycle Node', 'type' => 'article'],
        ]);
        $createData = json_decode($createResult['content'][0]['text'], true);
        $entityId = $createData['id'];
        $this->assertNotNull($entityId);

        // READ.
        $readResult = $this->toolExecutor->execute('read_node', ['id' => $entityId]);
        $readData = json_decode($readResult['content'][0]['text'], true);
        $this->assertSame('Lifecycle Node', $readData['data']['title']);

        // UPDATE.
        $updateResult = $this->toolExecutor->execute('update_node', [
            'id' => $entityId,
            'attributes' => ['title' => 'Updated Lifecycle Node'],
        ]);
        $updateData = json_decode($updateResult['content'][0]['text'], true);
        $this->assertSame('Updated Lifecycle Node', $updateData['data']['title']);

        // QUERY - verify updated.
        $queryResult = $this->toolExecutor->execute('query_node', []);
        $queryData = json_decode($queryResult['content'][0]['text'], true);
        $this->assertSame(1, $queryData['count']);

        // DELETE.
        $deleteResult = $this->toolExecutor->execute('delete_node', ['id' => $entityId]);
        $deleteData = json_decode($deleteResult['content'][0]['text'], true);
        $this->assertSame('delete', $deleteData['operation']);

        // QUERY - verify empty.
        $queryResult2 = $this->toolExecutor->execute('query_node', []);
        $queryData2 = json_decode($queryResult2['content'][0]['text'], true);
        $this->assertSame(0, $queryData2['count']);
    }
}
