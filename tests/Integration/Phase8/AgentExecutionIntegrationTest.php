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
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;
use Waaseyaa\AI\Schema\SchemaRegistry;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * Agent execution with audit logging, tool calls, and MCP server integration.
 *
 * Exercises: waaseyaa/ai-agent (AgentExecutor, AgentContext, AgentResult,
 * AgentAction, McpServer) with waaseyaa/ai-schema (McpToolExecutor,
 * SchemaRegistry) and waaseyaa/entity, waaseyaa/user.
 */
#[CoversNothing]
final class AgentExecutionIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $nodeStorage;
    private McpToolExecutor $toolExecutor;
    private AgentExecutor $agentExecutor;
    private SchemaRegistry $registry;
    private User $adminUser;

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

        $this->toolExecutor = new McpToolExecutor($this->entityTypeManager);
        $this->agentExecutor = new AgentExecutor($this->toolExecutor);

        $schemaGen = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $toolGen = new McpToolGenerator($this->entityTypeManager);
        $this->registry = new SchemaRegistry($schemaGen, $toolGen);

        $this->adminUser = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);
    }

    #[Test]
    public function agentCreatesEntitiesViaMcpTools(): void
    {
        $agent = new ContentCreatorAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: [
                'title' => 'Agent-Created Article',
                'type' => 'article',
            ],
        );

        $result = $this->agentExecutor->execute($agent, $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Created', $result->message);
        $this->assertNotEmpty($result->actions);
        $this->assertSame('tool_call', $result->actions[0]->type);

        // Verify entity was created in storage.
        $entity = $this->nodeStorage->load(1);
        $this->assertNotNull($entity);
        $this->assertSame('Agent-Created Article', $entity->label());
    }

    #[Test]
    public function agentExecutionIsAuditLogged(): void
    {
        $agent = new ContentCreatorAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: ['title' => 'Audit Test', 'type' => 'page'],
        );

        $this->agentExecutor->execute($agent, $context);

        $auditLog = $this->agentExecutor->getAuditLog();
        $this->assertNotEmpty($auditLog);

        // Should have tool_call entries and an execute entry.
        $actions = array_map(fn($entry) => $entry->action, $auditLog);
        $this->assertContains('tool_call', $actions);
        $this->assertContains('execute', $actions);

        // All entries should reference the admin account.
        foreach ($auditLog as $entry) {
            $this->assertSame(1, $entry->accountId);
        }

        // The execute entry should be successful.
        $executeEntries = array_filter($auditLog, fn($e) => $e->action === 'execute');
        $executeEntry = array_values($executeEntries)[0];
        $this->assertTrue($executeEntry->success);
        $this->assertSame(ContentCreatorAgent::class, $executeEntry->agentId);
    }

    #[Test]
    public function dryRunDoesNotMakeChanges(): void
    {
        $agent = new ContentCreatorAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: ['title' => 'Dry Run Test', 'type' => 'article'],
            dryRun: true,
        );

        $result = $this->agentExecutor->dryRun($agent, $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('dry run', strtolower($result->message));

        // Entity should NOT be created.
        $allEntities = $this->nodeStorage->loadMultiple();
        $this->assertEmpty($allEntities);

        // Audit log should record dry_run.
        $auditLog = $this->agentExecutor->getAuditLog();
        $dryRunEntries = array_filter($auditLog, fn($e) => $e->action === 'dry_run');
        $this->assertNotEmpty($dryRunEntries);
    }

    #[Test]
    public function agentFailureIsLogged(): void
    {
        $agent = new FailingAgent();
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: [],
        );

        $result = $this->agentExecutor->execute($agent, $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('failed', strtolower($result->message));

        $auditLog = $this->agentExecutor->getAuditLog();
        $this->assertNotEmpty($auditLog);
        $lastEntry = end($auditLog);
        $this->assertFalse($lastEntry->success);
        $this->assertSame('execute', $lastEntry->action);
    }

    #[Test]
    public function mcpServerListToolsAndCallToolWithRealStorage(): void
    {
        $server = new McpServer($this->registry, $this->toolExecutor);

        // List tools.
        $toolList = $server->listTools();
        $this->assertArrayHasKey('tools', $toolList);
        $this->assertCount(5, $toolList['tools']);

        $toolNames = array_column($toolList['tools'], 'name');
        $this->assertContains('create_node', $toolNames);
        $this->assertContains('read_node', $toolNames);

        // Call tool to create.
        $createResult = $server->callTool('create_node', [
            'attributes' => ['title' => 'MCP Server Node', 'type' => 'article'],
        ]);
        $this->assertArrayNotHasKey('isError', $createResult);

        $data = json_decode($createResult['content'][0]['text'], true);
        $this->assertSame('create', $data['operation']);

        // Call tool to read.
        $readResult = $server->callTool('read_node', ['id' => $data['id']]);
        $readData = json_decode($readResult['content'][0]['text'], true);
        $this->assertSame('MCP Server Node', $readData['data']['title']);

        // Call unknown tool.
        $unknownResult = $server->callTool('nonexistent_tool', []);
        $this->assertTrue($unknownResult['isError']);
    }

    #[Test]
    public function agentExecutorToolCallIsAuditLogged(): void
    {
        $context = new AgentContext(
            account: $this->adminUser,
            parameters: [],
        );

        $result = $this->agentExecutor->executeTool('create_node', [
            'attributes' => ['title' => 'Direct Tool Call', 'type' => 'page'],
        ], $context);

        $this->assertArrayNotHasKey('isError', $result);

        // Verify audit log for tool call.
        $auditLog = $this->agentExecutor->getAuditLog();
        $this->assertCount(1, $auditLog);
        $this->assertSame('tool_call', $auditLog[0]->action);
        $this->assertTrue($auditLog[0]->success);
        $this->assertSame('Tool call: create_node', $auditLog[0]->message);
        $this->assertSame(1, $auditLog[0]->accountId);
    }

    #[Test]
    public function agentActsAsSpecificUser(): void
    {
        $editorUser = new User([
            'uid' => 42,
            'name' => 'editor',
            'permissions' => ['edit articles'],
            'roles' => ['editor'],
        ]);

        $agent = new ContentCreatorAgent($this->agentExecutor);
        $context = new AgentContext(
            account: $editorUser,
            parameters: ['title' => 'Editor Content', 'type' => 'article'],
        );

        $result = $this->agentExecutor->execute($agent, $context);

        $this->assertTrue($result->success);

        // Verify audit log references the editor's account ID.
        $auditLog = $this->agentExecutor->getAuditLog();
        foreach ($auditLog as $entry) {
            $this->assertSame(42, $entry->accountId);
        }
    }

    #[Test]
    public function multipleAgentExecutionsAccumulateAuditLog(): void
    {
        $agent = new ContentCreatorAgent($this->agentExecutor);

        for ($i = 1; $i <= 3; $i++) {
            $context = new AgentContext(
                account: $this->adminUser,
                parameters: ['title' => "Node {$i}", 'type' => 'article'],
            );
            $this->agentExecutor->execute($agent, $context);
        }

        $auditLog = $this->agentExecutor->getAuditLog();
        // Each execution produces at least 2 entries (tool_call + execute).
        $this->assertGreaterThanOrEqual(6, count($auditLog));

        $executeEntries = array_filter($auditLog, fn($e) => $e->action === 'execute');
        $this->assertCount(3, $executeEntries);

        // Verify 3 entities created.
        $allEntities = $this->nodeStorage->loadMultiple();
        $this->assertCount(3, $allEntities);
    }
}

/**
 * Test agent that creates content using MCP tool calls.
 */
class ContentCreatorAgent implements AgentInterface
{
    public function __construct(
        private readonly AgentExecutor $executor,
    ) {}

    public function execute(AgentContext $context): AgentResult
    {
        $title = $context->parameters['title'] ?? 'Untitled';
        $type = $context->parameters['type'] ?? 'article';

        $toolResult = $this->executor->executeTool('create_node', [
            'attributes' => ['title' => $title, 'type' => $type],
        ], $context);

        $isError = $toolResult['isError'] ?? false;
        if ($isError) {
            return AgentResult::failure('Failed to create content.');
        }

        $data = json_decode($toolResult['content'][0]['text'], true);

        return AgentResult::success(
            message: "Created node: {$title}",
            data: ['entity_id' => $data['id'] ?? null],
            actions: [
                new AgentAction(
                    type: 'tool_call',
                    description: 'Called create_node tool',
                    data: ['tool' => 'create_node', 'title' => $title],
                ),
            ],
        );
    }

    public function dryRun(AgentContext $context): AgentResult
    {
        $title = $context->parameters['title'] ?? 'Untitled';

        return AgentResult::success(
            message: "Dry run: would create node '{$title}'",
            data: ['title' => $title],
            actions: [
                new AgentAction(
                    type: 'create',
                    description: "Would create node: {$title}",
                ),
            ],
        );
    }

    public function describe(): string
    {
        return 'Creates content entities via MCP tool calls.';
    }
}

/**
 * Test agent that always throws an exception.
 */
class FailingAgent implements AgentInterface
{
    public function execute(AgentContext $context): AgentResult
    {
        throw new \RuntimeException('Agent intentionally failed for testing.');
    }

    public function dryRun(AgentContext $context): AgentResult
    {
        throw new \RuntimeException('Agent dry-run intentionally failed.');
    }

    public function describe(): string
    {
        return 'An agent that always fails (for testing).';
    }
}
