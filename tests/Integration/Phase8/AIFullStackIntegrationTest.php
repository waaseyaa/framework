<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase8;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\AgentAction;
use Waaseyaa\AI\Agent\AgentContext;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\AgentInterface;
use Waaseyaa\AI\Agent\AgentResult;
use Waaseyaa\AI\Agent\McpServer;
use Waaseyaa\AI\Agent\ToolRegistry;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineExecutor;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;
use Waaseyaa\AI\Schema\SchemaRegistry;
use Waaseyaa\AI\Vector\EntityEmbedder;
use Waaseyaa\AI\Vector\InMemoryVectorStore;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * Full-stack integration test combining all AI layer packages.
 *
 * Exercises: waaseyaa/ai-schema, waaseyaa/ai-agent, waaseyaa/ai-vector,
 * waaseyaa/ai-pipeline together with waaseyaa/entity, waaseyaa/user.
 *
 * Scenario: set up entity storage, create entities via agent tool calls,
 * embed entities in vector store, search for similar content, and run a
 * pipeline that queries, embeds, and reports on entities.
 */
#[CoversNothing]
final class AIFullStackIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $nodeStorage;
    private McpToolExecutor $toolExecutor;
    private AgentExecutor $agentExecutor;
    private SchemaRegistry $registry;
    private McpServer $mcpServer;
    private FakeEmbeddingProvider $embeddingProvider;
    private InMemoryVectorStore $vectorStore;
    private EntityEmbedder $embedder;
    private User $adminUser;

    protected function setUp(): void
    {
        // Entity layer.
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

        // AI schema layer.
        $schemaGen = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $toolGen = new McpToolGenerator($this->entityTypeManager);
        $this->toolExecutor = new McpToolExecutor($this->entityTypeManager);
        $this->registry = new SchemaRegistry($schemaGen, $toolGen);

        // AI agent layer.
        $toolRegistry = new ToolRegistry();
        $this->registry->registerEntityTools($toolRegistry, $this->toolExecutor);
        $this->agentExecutor = new AgentExecutor($toolRegistry);
        $this->mcpServer = new McpServer($toolRegistry);

        // AI vector layer.
        $this->embeddingProvider = new FakeEmbeddingProvider(128);
        $this->vectorStore = new InMemoryVectorStore();
        $this->embedder = new EntityEmbedder($this->embeddingProvider, $this->vectorStore);

        // User.
        $this->adminUser = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);
    }

    #[Test]
    public function fullAIWorkflow(): void
    {
        // ---- Step 1: Schema and tools are available ----
        $schema = $this->registry->getSchema('node');
        $this->assertSame('Node', $schema['title']);

        $tools = $this->registry->getTools();
        $this->assertCount(5, $tools);

        // ---- Step 2: Agent creates entities via MCP tools ----
        $articles = [
            ['title' => 'Introduction to Machine Learning', 'type' => 'article'],
            ['title' => 'Deep Learning with Neural Networks', 'type' => 'article'],
            ['title' => 'Natural Language Processing Basics', 'type' => 'article'],
            ['title' => 'About Our Company', 'type' => 'page'],
        ];

        $agent = new BulkContentAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: ['items' => $articles],
        );

        $result = $this->agentExecutor->execute($agent, $context);
        $this->assertTrue($result->success);
        $this->assertSame(4, $result->data['created_count']);

        // Verify entities exist.
        $allEntities = $this->nodeStorage->loadMultiple();
        $this->assertCount(4, $allEntities);

        // ---- Step 3: Embed created entities ----
        foreach ($allEntities as $entity) {
            $this->embedder->embedEntity($entity);
        }

        $this->assertTrue($this->vectorStore->has('node', 1));
        $this->assertTrue($this->vectorStore->has('node', 2));
        $this->assertTrue($this->vectorStore->has('node', 3));
        $this->assertTrue($this->vectorStore->has('node', 4));

        // ---- Step 4: Search for similar entities ----
        $searchResults = $this->embedder->searchSimilar('machine learning AI', 3, 'node');
        $this->assertCount(3, $searchResults);
        // All results should be node entities.
        foreach ($searchResults as $sr) {
            $this->assertSame('node', $sr->embedding->entityTypeId);
        }

        // ---- Step 5: Verify audit trail ----
        $auditLog = $this->agentExecutor->getAuditLog();
        $this->assertNotEmpty($auditLog);

        $toolCalls = array_filter($auditLog, fn($e) => $e->action === 'tool_call');
        $this->assertCount(4, $toolCalls); // One create_node call per article.

        $executeEntries = array_filter($auditLog, fn($e) => $e->action === 'execute');
        $this->assertCount(1, $executeEntries);

        // All entries reference admin account.
        foreach ($auditLog as $entry) {
            $this->assertSame(1, $entry->accountId);
        }
    }

    #[Test]
    public function pipelineQueriesAndEmbedsEntities(): void
    {
        // Pre-create entities via MCP tools.
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Pipeline Node Alpha', 'type' => 'article'],
        ]);
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Pipeline Node Beta', 'type' => 'article'],
        ]);
        $this->toolExecutor->execute('create_node', [
            'attributes' => ['title' => 'Pipeline Node Gamma', 'type' => 'page'],
        ]);

        // Create a pipeline with steps that:
        // 1. Count entities in storage
        // 2. Embed entities
        // 3. Report results
        $pipeline = new Pipeline([
            'id' => 'entity_processor',
            'label' => 'Entity Processor',
            'description' => 'Queries, embeds, and reports on entities.',
            'steps' => [
                ['id' => 'query', 'plugin_id' => 'entity_query', 'label' => 'Query Entities', 'weight' => 0],
                ['id' => 'embed', 'plugin_id' => 'entity_embed', 'label' => 'Embed Entities', 'weight' => 10],
                ['id' => 'report', 'plugin_id' => 'report', 'label' => 'Report', 'weight' => 20],
            ],
        ]);

        $executor = new PipelineExecutor([
            'entity_query' => new EntityQueryStep($this->nodeStorage),
            'entity_embed' => new EntityEmbedStep($this->embedder),
            'report' => new ReportStep(),
        ]);

        $result = $executor->execute($pipeline, []);

        $this->assertTrue($result->success);
        $this->assertSame('Pipeline completed successfully.', $result->message);
        $this->assertCount(3, $result->stepResults);

        // Verify final output has the report.
        $this->assertArrayHasKey('report', $result->finalOutput);
        $this->assertSame(3, $result->finalOutput['report']['total_entities']);
        $this->assertSame(3, $result->finalOutput['report']['total_embedded']);

        // Verify embeddings were stored.
        $this->assertTrue($this->vectorStore->has('node', 1));
        $this->assertTrue($this->vectorStore->has('node', 2));
        $this->assertTrue($this->vectorStore->has('node', 3));
    }

    #[Test]
    public function agentModifiesEntitiesThenEmbeddingsReflectChanges(): void
    {
        // Create an entity.
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: [],
        );

        $this->agentExecutor->executeTool('create_node', [
            'attributes' => ['title' => 'Original Content', 'type' => 'article'],
        ], $context);

        $entity = $this->nodeStorage->load(1);
        $this->assertNotNull($entity);

        // Embed with original content.
        $embedding1 = $this->embedder->embedEntity($entity);

        // Update via tool.
        $this->agentExecutor->executeTool('update_node', [
            'id' => 1,
            'attributes' => ['title' => 'Completely Rewritten Content About AI'],
        ], $context);

        // Reload and re-embed.
        $updatedEntity = $this->nodeStorage->load(1);
        $embedding2 = $this->embedder->embedEntity($updatedEntity);

        // Embeddings should differ since content changed.
        $this->assertNotSame($embedding1->vector, $embedding2->vector);
        $this->assertSame('Completely Rewritten Content About AI', $embedding2->metadata['label']);
    }

    #[Test]
    public function mcpServerAndVectorSearchEndToEnd(): void
    {
        $server = $this->mcpServer;

        // Create entities via MCP server.
        $server->callTool('create_node', [
            'attributes' => ['title' => 'React Framework Guide', 'type' => 'article'],
        ]);
        $server->callTool('create_node', [
            'attributes' => ['title' => 'Vue.js Getting Started', 'type' => 'article'],
        ]);
        $server->callTool('create_node', [
            'attributes' => ['title' => 'Angular Architecture Patterns', 'type' => 'article'],
        ]);

        // Query via MCP server.
        $queryResult = $server->callTool('query_node', []);
        $queryData = json_decode($queryResult['content'][0]['text'], true);
        $this->assertSame(3, $queryData['count']);

        // Embed all entities.
        foreach ($this->nodeStorage->loadMultiple() as $entity) {
            $this->embedder->embedEntity($entity);
        }

        // Search for framework-related content.
        $results = $this->embedder->searchSimilar('JavaScript frontend framework', 3);
        $this->assertCount(3, $results);

        // Delete one entity via MCP, remove its embedding.
        $server->callTool('delete_node', ['id' => 2]);
        $this->embedder->removeEntity('node', 2);

        // Verify entity and embedding are gone.
        $readResult = $server->callTool('read_node', ['id' => 2]);
        $this->assertTrue($readResult['isError']);
        $this->assertFalse($this->vectorStore->has('node', 2));

        // Search should return only 2 results.
        $results = $this->embedder->searchSimilar('JavaScript', 10, 'node');
        $this->assertCount(2, $results);
    }

    #[Test]
    public function dryRunAgentDoesNotAffectStorageOrVectors(): void
    {
        $agent = new BulkContentAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: ['items' => [
                ['title' => 'Should Not Exist', 'type' => 'article'],
            ]],
            dryRun: true,
        );

        $result = $this->agentExecutor->dryRun($agent, $context);
        $this->assertTrue($result->success);

        // Storage should be empty.
        $this->assertEmpty($this->nodeStorage->loadMultiple());

        // Vector store should be empty.
        $this->assertFalse($this->vectorStore->has('node', 1));
    }

    #[Test]
    public function pipelineWithAgentToolCallStep(): void
    {
        // Pipeline that uses agent tool calls to create entities.
        $pipeline = new Pipeline([
            'id' => 'agent_pipeline',
            'label' => 'Agent Pipeline',
            'steps' => [
                ['id' => 'create', 'plugin_id' => 'tool_create', 'label' => 'Create Entities', 'weight' => 0],
                ['id' => 'count', 'plugin_id' => 'entity_count', 'label' => 'Count Entities', 'weight' => 10],
            ],
        ]);

        $executor = new PipelineExecutor([
            'tool_create' => new ToolCreateStep($this->toolExecutor),
            'entity_count' => new EntityCountStep($this->nodeStorage),
        ]);

        $result = $executor->execute($pipeline, [
            'titles' => ['Alpha', 'Beta', 'Gamma'],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->finalOutput['entity_count']);
    }
}

// ---- Test Agent Implementation ----

/**
 * Agent that creates multiple content entities in bulk.
 */
class BulkContentAgent implements AgentInterface
{
    public function __construct(
        private readonly AgentExecutor $executor,
    ) {}

    public function execute(AgentContext $context): AgentResult
    {
        $items = $context->parameters['items'] ?? [];
        $actions = [];
        $createdIds = [];

        foreach ($items as $item) {
            $toolResult = $this->executor->executeTool('create_node', [
                'attributes' => $item,
            ], $context);

            $isError = $toolResult['isError'] ?? false;
            if ($isError) {
                return AgentResult::failure("Failed to create: {$item['title']}");
            }

            $data = json_decode($toolResult['content'][0]['text'], true);
            $createdIds[] = $data['id'];

            $actions[] = new AgentAction(
                type: 'tool_call',
                description: "Created node: {$item['title']}",
                data: ['tool' => 'create_node', 'id' => $data['id']],
            );
        }

        return AgentResult::success(
            message: 'Created ' . count($createdIds) . ' entities.',
            data: ['created_ids' => $createdIds, 'created_count' => count($createdIds)],
            actions: $actions,
        );
    }

    public function dryRun(AgentContext $context): AgentResult
    {
        $items = $context->parameters['items'] ?? [];
        $actions = [];

        foreach ($items as $item) {
            $actions[] = new AgentAction(
                type: 'create',
                description: "Would create: {$item['title']}",
            );
        }

        return AgentResult::success(
            message: 'Dry run: would create ' . count($items) . ' entities.',
            data: ['planned_count' => count($items)],
            actions: $actions,
        );
    }

    public function describe(): string
    {
        return 'Creates multiple content entities in bulk via MCP tools.';
    }
}

// ---- Test Pipeline Step Implementations ----

/**
 * Pipeline step that queries entities from storage.
 */
class EntityQueryStep implements PipelineStepInterface
{
    public function __construct(
        private readonly InMemoryEntityStorage $storage,
    ) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $entities = $this->storage->loadMultiple();
        $entityData = [];
        foreach ($entities as $entity) {
            $entityData[] = [
                'id' => $entity->id(),
                'label' => $entity->label(),
                'type_id' => $entity->getEntityTypeId(),
            ];
        }

        return StepResult::success(
            ['entities' => $entityData, 'count' => count($entityData)],
            'Queried ' . count($entityData) . ' entities.',
        );
    }

    public function describe(): string
    {
        return 'Queries entities from storage.';
    }
}

/**
 * Pipeline step that embeds entities into the vector store.
 */
class EntityEmbedStep implements PipelineStepInterface
{
    public function __construct(
        private readonly EntityEmbedder $embedder,
    ) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $entities = $input['entities'] ?? [];
        $embedded = 0;

        foreach ($entities as $entityData) {
            // Create a minimal entity for embedding.
            $entity = new TestEntity(
                values: [
                    'id' => $entityData['id'],
                    'title' => $entityData['label'],
                    'type' => 'article',
                ],
                entityTypeId: $entityData['type_id'],
            );
            $this->embedder->embedEntity($entity);
            $embedded++;
        }

        return StepResult::success(
            array_merge($input, ['embedded_count' => $embedded]),
            "Embedded {$embedded} entities.",
        );
    }

    public function describe(): string
    {
        return 'Embeds entities into vector store.';
    }
}

/**
 * Pipeline step that produces a summary report.
 */
class ReportStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        $report = [
            'total_entities' => $input['count'] ?? 0,
            'total_embedded' => $input['embedded_count'] ?? 0,
            'pipeline_id' => $context->pipelineId,
        ];

        return StepResult::success(
            ['report' => $report],
            'Report generated.',
        );
    }

    public function describe(): string
    {
        return 'Generates a summary report.';
    }
}

/**
 * Pipeline step that creates entities using MCP tool executor.
 */
class ToolCreateStep implements PipelineStepInterface
{
    public function __construct(
        private readonly McpToolExecutor $toolExecutor,
    ) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $titles = $input['titles'] ?? [];
        $createdIds = [];

        foreach ($titles as $title) {
            $result = $this->toolExecutor->execute('create_node', [
                'attributes' => ['title' => $title, 'type' => 'article'],
            ]);
            $data = json_decode($result['content'][0]['text'], true);
            $createdIds[] = $data['id'];
        }

        return StepResult::success(
            ['created_ids' => $createdIds, 'created_count' => count($createdIds)],
            'Created ' . count($createdIds) . ' entities.',
        );
    }

    public function describe(): string
    {
        return 'Creates entities via MCP tool calls.';
    }
}

/**
 * Pipeline step that counts entities in storage.
 */
class EntityCountStep implements PipelineStepInterface
{
    public function __construct(
        private readonly InMemoryEntityStorage $storage,
    ) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $count = count($this->storage->loadMultiple());

        return StepResult::success(
            array_merge($input, ['entity_count' => $count]),
            "Found {$count} entities.",
        );
    }

    public function describe(): string
    {
        return 'Counts entities in storage.';
    }
}
