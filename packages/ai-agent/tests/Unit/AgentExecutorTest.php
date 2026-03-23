<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentContext;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\AgentResult;
use Waaseyaa\AI\Agent\ToolRegistry;
use Waaseyaa\AI\Agent\Provider\MaxIterationsException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentExecutor::class)]
final class AgentExecutorTest extends TestCase
{
    private AgentExecutor $executor;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $this->executor = new AgentExecutor($this->registry);
    }

    private function createContext(int $accountId = 1, array $parameters = []): AgentContext
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($accountId);

        return new AgentContext(
            account: $account,
            parameters: $parameters,
        );
    }

    public function testExecuteAgent(): void
    {
        $agent = new TestAgent();
        $context = $this->createContext(1, ['title' => 'My Article']);

        $result = $this->executor->execute($agent, $context);

        self::assertTrue($result->success);
        self::assertSame('Test agent executed', $result->message);
        self::assertSame(['parameters' => ['title' => 'My Article']], $result->data);
        self::assertCount(1, $result->actions);
        self::assertSame('create', $result->actions[0]->type);
    }

    public function testDryRunAgent(): void
    {
        $agent = new TestAgent();
        $context = $this->createContext(2, ['type' => 'article']);

        $result = $this->executor->dryRun($agent, $context);

        self::assertTrue($result->success);
        self::assertSame('Test agent would create entity', $result->message);
        self::assertCount(1, $result->actions);
        self::assertSame('create', $result->actions[0]->type);
        self::assertSame('Would create test entity', $result->actions[0]->description);
    }

    public function testAuditLogCapturesExecution(): void
    {
        $agent = new TestAgent();
        $context = $this->createContext(5);

        $this->executor->execute($agent, $context);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertSame(TestAgent::class, $log[0]->agentId);
        self::assertSame(5, $log[0]->accountId);
        self::assertSame('execute', $log[0]->action);
        self::assertTrue($log[0]->success);
        self::assertSame('Test agent executed', $log[0]->message);
        self::assertGreaterThan(0, $log[0]->timestamp);
    }

    public function testAuditLogCapturesDryRun(): void
    {
        $agent = new TestAgent();
        $context = $this->createContext(3);

        $this->executor->dryRun($agent, $context);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertSame('dry_run', $log[0]->action);
        self::assertTrue($log[0]->success);
    }

    public function testFailedAgentLoggedAsFailure(): void
    {
        $agent = new TestAgent();
        $agent->setExecuteResult(AgentResult::failure('Access denied'));
        $context = $this->createContext(7);

        $result = $this->executor->execute($agent, $context);

        self::assertFalse($result->success);
        self::assertSame('Access denied', $result->message);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertFalse($log[0]->success);
        self::assertSame('Access denied', $log[0]->message);
    }

    public function testExceptionHandlingWrapsAsFailure(): void
    {
        $agent = new TestAgent();
        $agent->setExecuteException(new \RuntimeException('Database connection lost'));
        $context = $this->createContext(1);

        $result = $this->executor->execute($agent, $context);

        self::assertFalse($result->success);
        self::assertStringContainsString('Database connection lost', $result->message);
        self::assertSame(\RuntimeException::class, $result->data['exception']);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertFalse($log[0]->success);
        self::assertStringContainsString('Database connection lost', $log[0]->message);
    }

    public function testDryRunExceptionHandling(): void
    {
        $agent = new TestAgent();
        $agent->setDryRunException(new \InvalidArgumentException('Invalid parameters'));
        $context = $this->createContext(1);

        $result = $this->executor->dryRun($agent, $context);

        self::assertFalse($result->success);
        self::assertStringContainsString('Invalid parameters', $result->message);
        self::assertSame(\InvalidArgumentException::class, $result->data['exception']);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertSame('dry_run', $log[0]->action);
        self::assertFalse($log[0]->success);
    }

    public function testToolExecution(): void
    {
        $this->registry->register(
            new McpToolDefinition(name: 'create_node', description: 'Create node', inputSchema: []),
            fn (array $args) => [
                'content' => [['type' => 'text', 'text' => \json_encode(['created' => true], \JSON_THROW_ON_ERROR)]],
            ],
        );

        $context = $this->createContext(10);
        $result = $this->executor->executeTool('create_node', ['attributes' => ['title' => 'Test']], $context);

        self::assertArrayHasKey('content', $result);
        self::assertArrayNotHasKey('isError', $result);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertSame('tool', $log[0]->agentId);
        self::assertSame(10, $log[0]->accountId);
        self::assertSame('tool_call', $log[0]->action);
        self::assertTrue($log[0]->success);
        self::assertSame('Tool call: create_node', $log[0]->message);
        self::assertSame('create_node', $log[0]->data['tool']);
    }

    public function testToolExecutionWithUnknownTool(): void
    {
        $context = $this->createContext(1);
        $result = $this->executor->executeTool('create_unknown', ['attributes' => []], $context);

        self::assertTrue($result['isError']);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertFalse($log[0]->success);
    }

    public function testToolExecutionWithInvalidTool(): void
    {
        $context = $this->createContext(1);
        $result = $this->executor->executeTool('totally_invalid', [], $context);

        self::assertArrayHasKey('content', $result);

        $log = $this->executor->getAuditLog();
        self::assertCount(1, $log);
        self::assertFalse($log[0]->success);
    }

    public function testMultipleExecutionsAccumulateAuditLog(): void
    {
        $agent = new TestAgent();
        $context = $this->createContext(1);

        $this->executor->execute($agent, $context);
        $this->executor->dryRun($agent, $context);
        $this->executor->execute($agent, $context);

        $log = $this->executor->getAuditLog();
        self::assertCount(3, $log);
        self::assertSame('execute', $log[0]->action);
        self::assertSame('dry_run', $log[1]->action);
        self::assertSame('execute', $log[2]->action);
    }

    public function testAuditLogStartsEmpty(): void
    {
        self::assertSame([], $this->executor->getAuditLog());
    }

    // --- Provider tests (Task 10) ---

    public function testExecuteWithProviderSingleTurnNoTools(): void
    {
        $registry = new ToolRegistry();
        $executor = new AgentExecutor($registry);

        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'Hello from Claude']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 10, 'output_tokens' => 5],
                );
            }
        };

        $agent = new TestAgent(AgentResult::success('prepared'));
        $context = new AgentContext(account: new TestAccount());

        $result = $executor->executeWithProvider($agent, $context, $provider);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Hello from Claude', $result->message);
    }

    public function testExecuteWithProviderMultiTurnToolLoop(): void
    {
        $registry = new ToolRegistry();
        $registry->register(
            new McpToolDefinition(name: 'read_node', description: 'Read', inputSchema: []),
            fn (array $args) => [
                'content' => [['type' => 'text', 'text' => '{"id": 1, "title": "Found"}']],
            ],
        );

        $executor = new AgentExecutor($registry);

        $callCount = 0;
        $provider = new class ($callCount) implements ProviderInterface {
            public function __construct(private int &$callCount) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    return new MessageResponse(
                        content: [
                            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read_node', 'input' => ['id' => '1']],
                        ],
                        stopReason: 'tool_use',
                    );
                }
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'I found the node.']],
                    stopReason: 'end_turn',
                );
            }
        };

        $agent = new TestAgent(AgentResult::success('prepared'));
        $context = new AgentContext(account: new TestAccount());

        $result = $executor->executeWithProvider($agent, $context, $provider);

        $this->assertTrue($result->success);
        $this->assertSame(2, $callCount);
    }

    public function testExecuteWithProviderRespectsMaxIterations(): void
    {
        $registry = new ToolRegistry();
        $registry->register(
            new McpToolDefinition(name: 'loop_tool', description: 'Loops', inputSchema: []),
            fn (array $args) => ['content' => [['type' => 'text', 'text' => 'ok']]],
        );

        $executor = new AgentExecutor($registry);

        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'loop_tool', 'input' => []]],
                    stopReason: 'tool_use',
                );
            }
        };

        $agent = new TestAgent(AgentResult::success('prepared'));
        $context = new AgentContext(account: new TestAccount(), maxIterations: 3);

        $this->expectException(MaxIterationsException::class);

        $executor->executeWithProvider($agent, $context, $provider);
    }

    // --- Streaming tests (Task 13) ---

    public function testStreamWithProviderForwardsChunks(): void
    {
        $registry = new ToolRegistry();
        $executor = new AgentExecutor($registry);

        $provider = new class implements StreamingProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'Hello world']],
                    stopReason: 'end_turn',
                );
            }

            public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
            {
                $onChunk(new StreamChunk(type: 'text_delta', text: 'Hello'));
                $onChunk(new StreamChunk(type: 'text_delta', text: ' world'));
                $onChunk(new StreamChunk(type: 'message_stop'));

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'Hello world']],
                    stopReason: 'end_turn',
                );
            }
        };

        $receivedChunks = [];
        $agent = new TestAgent(AgentResult::success('prepared'));
        $context = new AgentContext(account: new TestAccount());

        $result = $executor->streamWithProvider(
            $agent,
            $context,
            $provider,
            function (StreamChunk $chunk) use (&$receivedChunks): void {
                $receivedChunks[] = $chunk;
            },
        );

        $this->assertTrue($result->success);
        $this->assertCount(3, $receivedChunks);
        $this->assertSame('Hello', $receivedChunks[0]->text);
        $this->assertSame(' world', $receivedChunks[1]->text);
    }
}
