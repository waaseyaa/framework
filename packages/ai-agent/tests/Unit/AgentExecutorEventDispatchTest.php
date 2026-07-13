<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\ClientErrorException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunIterationCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunProviderCallCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunStarted;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Unit regression coverage for the five lifecycle event dispatches from
 * {@see AgentExecutor} (FR-011).
 *
 * Uses an in-memory SQLite database (same pattern as ExecutorHitlTest) so
 * the entity-storage pipeline is real. The EventDispatcherInterface is
 * supplied as a spy that records all dispatched events.
 */
#[CoversClass(AgentExecutor::class)]
final class AgentExecutorEventDispatchTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();
        $this->migrateSchema();

        $symdispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);

        $runType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $logType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );

        $runEntityRepo = new EntityRepository($runType, $driver, $symdispatcher);
        $logEntityRepo = new EntityRepository($logType, $driver, $symdispatcher);

        $this->runRepository = new AgentRunRepository($runEntityRepo, $this->database);
        $this->auditRepository = new AgentAuditLogRepository($logEntityRepo, $this->database);
    }

    #[Test]
    public function dispatchesAllFiveEventsOnHappyPath(): void
    {
        $run = $this->seedRun();

        // Provider: one tool-use turn, then a final text turn.
        $provider = new class implements ProviderInterface {
            private int $call = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MessageResponse(
                        content: [
                            ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'echo_tool', 'input' => ['text' => 'hi']],
                        ],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 10, 'output_tokens' => 5],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'done']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 10, 'output_tokens' => 5],
                );
            }
        };

        $echoTool = $this->makeEchoTool('echo_tool');
        $registry = $this->registryWith([$echoTool]);

        $spy = new RecordingEventDispatcher();
        $executor = $this->makeExecutor($registry, $spy);

        $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: ['echo_tool'],
            maxIterations: 5,
        );

        $classes = array_map(static fn(object $e): string => $e::class, $spy->dispatched);

        self::assertContains(AgentRunStarted::class, $classes, 'AgentRunStarted must be dispatched');
        self::assertContains(AgentRunProviderCallCompleted::class, $classes, 'AgentRunProviderCallCompleted must be dispatched');
        self::assertContains(AgentRunToolCallObserved::class, $classes, 'AgentRunToolCallObserved must be dispatched');
        self::assertContains(AgentRunIterationCompleted::class, $classes, 'AgentRunIterationCompleted must be dispatched');
        self::assertContains(AgentRunTerminated::class, $classes, 'AgentRunTerminated must be dispatched');

        // Exactly one AgentRunStarted per run (FR-005).
        $started = array_filter($spy->dispatched, static fn(object $e): bool => $e instanceof AgentRunStarted);
        self::assertCount(1, $started, 'AgentRunStarted dispatched exactly once');

        // Exactly one AgentRunTerminated per run (FR-005).
        $terminated = array_filter($spy->dispatched, static fn(object $e): bool => $e instanceof AgentRunTerminated);
        self::assertCount(1, $terminated, 'AgentRunTerminated dispatched exactly once');
    }

    #[Test]
    public function listenerExceptionDoesNotAbortRun(): void
    {
        $run = $this->seedRun();

        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'result']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };

        // Dispatcher that throws on every dispatch call.
        $throwingDispatcher = new class implements EventDispatcherInterface {
            public array $errors = [];

            public function dispatch(object $event, ?string $eventName = null): object
            {
                throw new \RuntimeException('listener exploded: ' . $event::class);
            }

            public function addListener(string $eventName, callable $listener, int $priority = 0): void {}

            public function addSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void {}

            public function removeListener(string $eventName, callable $listener): void {}

            public function removeSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void {}

            public function getListeners(?string $eventName = null): array
            {
                return [];
            }
        };

        $registry = $this->registryWith([]);
        $executor = $this->makeExecutor($registry, $throwingDispatcher);

        // Must complete without throwing despite dispatcher always throwing.
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 3,
        );

        self::assertTrue($result->success, 'Run must succeed even when listeners throw');
    }

    #[Test]
    public function sequenceCounterIncrementsPerIteration(): void
    {
        $run = $this->seedRun();

        // Provider: tool_use on call 1, end_turn on call 2 → two iterations.
        $provider = new class implements ProviderInterface {
            private int $call = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MessageResponse(
                        content: [
                            ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'noop', 'input' => []],
                        ],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'finished']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 2],
                );
            }
        };

        $noopTool = $this->makeEchoTool('noop');
        $registry = $this->registryWith([$noopTool]);

        $spy = new RecordingEventDispatcher();
        $executor = $this->makeExecutor($registry, $spy);

        $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: ['noop'],
            maxIterations: 5,
        );

        $iterations = array_values(array_filter(
            $spy->dispatched,
            static fn(object $e): bool => $e instanceof AgentRunIterationCompleted,
        ));

        self::assertCount(2, $iterations, 'AgentRunIterationCompleted dispatched twice (two iterations)');
        self::assertSame(0, $iterations[0]->iterationIndex, 'First iteration has index 0');
        self::assertSame(1, $iterations[1]->iterationIndex, 'Second iteration has index 1');
    }

    #[Test]
    public function toolCallObservedCarriesInitiatorAccountOnSuccessPath(): void
    {
        $run = $this->seedRun();
        $provider = $this->toolUseThenEndTurnProvider('ok_tool');
        $registry = $this->registryWith([$this->makeSucceedingTool('ok_tool')]);

        $spy = new RecordingEventDispatcher();
        $executor = $this->makeExecutor($registry, $spy);

        $executor->executeRun(
            $run,
            $this->fakeAccount(42),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: ['ok_tool'],
            maxIterations: 5,
        );

        $observed = array_values(array_filter(
            $spy->dispatched,
            static fn(object $e): bool => $e instanceof AgentRunToolCallObserved,
        ));

        self::assertCount(1, $observed);
        self::assertTrue($observed[0]->succeeded);
        self::assertSame(42, $observed[0]->accountId, 'Success-path observed event must carry the initiator account (FR-005)');
    }

    #[Test]
    public function toolCallObservedCarriesInitiatorAccountOnThrewPath(): void
    {
        $run = $this->seedRun();
        $provider = $this->toolUseThenEndTurnProvider('boom_tool');
        $registry = $this->registryWith([$this->makeThrowingTool('boom_tool')]);

        $spy = new RecordingEventDispatcher();
        $executor = $this->makeExecutor($registry, $spy);

        $executor->executeRun(
            $run,
            $this->fakeAccount(42),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: ['boom_tool'],
            maxIterations: 5,
        );

        $observed = array_values(array_filter(
            $spy->dispatched,
            static fn(object $e): bool => $e instanceof AgentRunToolCallObserved,
        ));

        self::assertCount(1, $observed);
        self::assertFalse($observed[0]->succeeded, 'Tool threw — observed event records failure');
        self::assertSame(42, $observed[0]->accountId, 'Threw-path observed event must carry the initiator account too (FR-005)');
    }

    #[Test]
    public function accountContextIsScopedToInitiatorAndRestoredAfterRun(): void
    {
        $run = $this->seedRun();
        $provider = $this->endTurnProvider();
        $registry = $this->registryWith([]);

        $previousActor = $this->fakeAccount(9);
        $context = new RecordingAccountContext($previousActor);
        $initiator = $this->fakeAccount(42);

        $executor = $this->makeExecutor($registry, new RecordingEventDispatcher(), $context);
        $executor->executeRun(
            $run,
            $initiator,
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 3,
        );

        // set(initiator) at run start, set(previous) after — restored to the
        // PREVIOUS value (account 9), not blindly to null.
        self::assertSame([$initiator, $previousActor], $context->setCalls);
        self::assertSame($previousActor, $context->current());
    }

    #[Test]
    public function accountContextIsRestoredWhenTheRunThrows(): void
    {
        $run = $this->seedRun();

        // ClientErrorException is non-retryable and re-thrown out of
        // executeRun — the throw path crosses the context scope boundary.
        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                throw new ClientErrorException('bad request');
            }
        };

        $previousActor = $this->fakeAccount(9);
        $context = new RecordingAccountContext($previousActor);
        $initiator = $this->fakeAccount(42);

        $executor = $this->makeExecutor($this->registryWith([]), new RecordingEventDispatcher(), $context);

        try {
            $executor->executeRun(
                $run,
                $initiator,
                $provider,
                messages: [['role' => 'user', 'content' => 'go']],
                maxIterations: 3,
            );
            self::fail('ClientErrorException expected to propagate');
        } catch (ClientErrorException) {
            // expected
        }

        // The `finally` pin: a thrown run must not leak the initiator into
        // the next job on the same worker (plan premortem "stale actor").
        self::assertSame([$initiator, $previousActor], $context->setCalls);
        self::assertSame($previousActor, $context->current());
    }

    #[Test]
    public function executorWithoutAccountContextRunsWithoutError(): void
    {
        $run = $this->seedRun();
        $provider = $this->endTurnProvider();

        // Legacy construction: no accountContext param at all.
        $executor = $this->makeExecutor($this->registryWith([]), new RecordingEventDispatcher());

        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 3,
        );

        self::assertTrue($result->success);
    }

    #[Test]
    public function listValuedToolArgumentsAreAuditedAndDoNotCrashTheRun(): void
    {
        $run = $this->seedRun();

        // A tool_use turn carrying a LIST-valued argument (the shape an
        // entity.create `values.blocks` / `tags` produces), then end_turn.
        $provider = new class implements ProviderInterface {
            private int $call = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MessageResponse(
                        content: [[
                            'type' => 'tool_use',
                            'id' => 'tool-1',
                            'name' => 'list_arg_tool',
                            'input' => ['values' => ['blocks' => [['type' => 'prose']], 'tags' => ['a', 'b']]],
                        ]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'done']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 2],
                );
            }
        };

        $registry = $this->registryWith([$this->makeRealAuditTool('list_arg_tool')]);
        $executor = $this->makeExecutor($registry, new RecordingEventDispatcher());

        // Pre-#1637: argumentsForAudit() threw a TypeError on the integer list
        // keys at the audit step — which sits OUTSIDE the execute() try/catch —
        // so the run crashed and the tool_call audit row was never written.
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: ['list_arg_tool'],
            maxIterations: 5,
        );

        self::assertTrue($result->success, 'a list-valued tool argument must not crash the run');

        $logged = $this->auditRepository->findByRunId((string) $run->id());
        $toolCalls = array_filter(
            $logged,
            static fn(AgentAuditLog $log): bool => $log->getEventType() === EventType::ToolCall,
        );
        self::assertNotEmpty($toolCalls, 'the tool_call audit row (written right after argumentsForAudit) must be present');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeExecutor(
        ToolRegistryInterface $registry,
        EventDispatcherInterface $dispatcher,
        ?AccountContextInterface $accountContext = null,
    ): AgentExecutor {
        return new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
            eventDispatcher: $dispatcher,
            accountContext: $accountContext,
        );
    }

    /**
     * Provider issuing one `tool_use` turn for the named tool, then an
     * `end_turn` text turn.
     */
    private function toolUseThenEndTurnProvider(string $toolName): ProviderInterface
    {
        return new class ($toolName) implements ProviderInterface {
            private int $call = 0;

            public function __construct(private readonly string $toolName) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MessageResponse(
                        content: [
                            ['type' => 'tool_use', 'id' => 'tool-1', 'name' => $this->toolName, 'input' => []],
                        ],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'finished']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 2],
                );
            }
        };
    }

    private function endTurnProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'done']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };
    }

    private function makeSucceedingTool(string $name): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Always-succeeding tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    private function makeThrowingTool(string $name): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                throw new \RuntimeException('tool exploded');
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Always-throwing tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    private function seedRun(): AgentRun
    {
        $run = new AgentRun([
            'id' => '01J' . str_pad((string) random_int(1000, 9999), 23, '0'),
            'account_id' => 1,
            'agent_definition_id' => 'test-agent',
            'bundle_json' => '{}',
            'status' => RunStatus::Running->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'test',
            'response' => null,
            'transcript_json' => '',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);

        return $run;
    }

    private function fakeAccount(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['administrator'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function makeEchoTool(string $name): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('ok');
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('dry-ok');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Echo tool';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * A tool whose impl uses the REAL {@see AbstractAgentTool::argumentsForAudit()}
     * (not the pass-through fixtures), so a list-valued argument exercises the
     * #1637 audit path through {@see AgentExecutor}.
     */
    private function makeRealAuditTool(string $name): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('ok');
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Real-audit tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    private function registryWith(array $tools): ToolRegistryInterface
    {
        return new class ($tools) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                if (!isset($this->map[$name])) {
                    throw ToolNotFoundException::forName($name);
                }
                return $this->map[$name];
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            /** @return AgentTool[] */
            public function all(): array
            {
                return array_values($this->map);
            }
        };
    }

    private function migrateSchema(): void
    {
        // From packages/ai-agent/tests/Unit/ → up 2 levels → packages/ai-agent/
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $migration->up(new SchemaBuilder($this->database->getConnection()));
    }
}

/**
 * Recording {@see AccountContextInterface} stub: stores the live current
 * value and the full sequence of {@see set()} calls so tests can pin the
 * set-to-initiator / restore-to-previous discipline.
 */
final class RecordingAccountContext implements AccountContextInterface
{
    /** @var list<?AccountInterface> */
    public array $setCalls = [];

    public function __construct(private ?AccountInterface $current = null) {}

    public function current(): ?AccountInterface
    {
        return $this->current;
    }

    public function set(?AccountInterface $account): void
    {
        $this->setCalls[] = $account;
        $this->current = $account;
    }
}

/**
 * Spy EventDispatcherInterface that records all dispatched events.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    public function addListener(string $eventName, callable $listener, int $priority = 0): void {}

    public function addSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void {}

    public function removeListener(string $eventName, callable $listener): void {}

    public function removeSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void {}

    public function getListeners(?string $eventName = null): array
    {
        return [];
    }
}
