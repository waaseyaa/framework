<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AgentRun;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunProviderCallCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Observability\Listener\AgentRunTelemetryListener;
use Waaseyaa\AI\Observability\Recorder\AgentRunMetricsRecorderInterface;
use Waaseyaa\AI\Observability\Recorder\AgentTelescopeRecorderInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Full-stack integration test that boots the entity-storage pipeline,
 * executes a fake agent run through {@see AgentExecutor} wired with an
 * {@see EventDispatcherInterface}, and asserts the
 * {@see AgentRunTelemetryListener} recorder receives all five lifecycle events
 * (FR-014 / SC-002).
 *
 * No network or real filesystem — all storage is in-memory SQLite.
 */
#[CoversNothing]
final class AgentRunObservabilityTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;
    private EventDispatcher $symfonyDispatcher;
    private SpyingTelescopeRecorder $telescopeSpy;
    private SpyingMetricsRecorder $metricsSpy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();
        $this->migrateSchema();

        $this->symfonyDispatcher = new EventDispatcher();
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

        $runEntityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($runType, $driver, $this->symfonyDispatcher);
        $logEntityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($logType, $driver, $this->symfonyDispatcher);

        $this->runRepository = new AgentRunRepository($runEntityRepo, $this->database);
        $this->auditRepository = new AgentAuditLogRepository($logEntityRepo, $this->database);

        $this->telescopeSpy = new SpyingTelescopeRecorder();
        $this->metricsSpy = new SpyingMetricsRecorder();

        // Wire the real telemetry listener into the Symfony dispatcher.
        $this->symfonyDispatcher->addSubscriber(new AgentRunTelemetryListener(
            telescope: $this->telescopeSpy,
            runRepository: $this->runRepository,
            metrics: $this->metricsSpy,
        ));
    }

    #[Test]
    public function dispatchesAllFiveLifecycleEvents(): void
    {
        $run = $this->seedRun();
        $runId = (string) $run->get('id');

        // Provider: one tool-use turn, then a final text turn.
        $provider = new class implements ProviderInterface {
            private int $call = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new MessageResponse(
                        content: [
                            ['type' => 'tool_use', 'id' => 'obs-tool-1', 'name' => 'obs_tool', 'input' => ['key' => 'val']],
                        ],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 20, 'output_tokens' => 10],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'observation complete']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 15, 'output_tokens' => 8],
                );
            }
        };

        $obsTool = $this->makeSucceedingTool('obs_tool');
        $registry = $this->registryWith([$obsTool]);

        // Wrap the Symfony dispatcher in the Waaseyaa EventDispatcherInterface bridge.
        $dispatcher = new SymfonyEventDispatcherBridge($this->symfonyDispatcher);

        $executor = new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
            eventDispatcher: $dispatcher,
        );

        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(42),
            $provider,
            messages: [['role' => 'user', 'content' => 'observe']],
            allowedToolNames: ['obs_tool'],
            maxIterations: 5,
        );

        self::assertTrue($result->success, 'Run must succeed: ' . $result->message);

        // The telemetry listener should have flushed exactly one Telescope record
        // on AgentRunTerminated (which means all 5 events were received).
        self::assertCount(1, $this->telescopeSpy->records, 'Telescope must receive exactly one flush');

        $record = $this->telescopeSpy->records[0];
        self::assertSame($runId, $record['run_id'], 'run_id must match');
        self::assertSame('completed', $record['status'], 'status must be completed');
        self::assertGreaterThanOrEqual(1, $record['tool_call_count'], 'tool_call_count must be >= 1 (AgentRunToolCallObserved received)');
        self::assertNotEmpty($record['iteration_durations_ms'], 'iteration_durations_ms must have entries (AgentRunIterationCompleted received)');

        // The metrics recorder receives providerTokens on AgentRunProviderCallCompleted.
        self::assertNotEmpty($this->metricsSpy->providerTokens, 'Provider token metrics must be recorded (AgentRunProviderCallCompleted received)');

        // Exactly one terminal run metric (AgentRunTerminated received).
        self::assertCount(1, $this->metricsSpy->terminalRuns, 'Exactly one terminal run metric');
        self::assertSame('completed', $this->metricsSpy->terminalRuns[0]['status']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedRun(): AgentRun
    {
        $run = new AgentRun([
            'id' => '01K' . str_pad((string) random_int(1000, 9999), 23, '0'),
            'account_id' => 42,
            'agent_definition_id' => 'obs-agent',
            'bundle_json' => '{}',
            'status' => RunStatus::Running->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'observability test',
            'response' => null,
            'transcript_json' => '',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
            'started_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
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

    private function makeSucceedingTool(string $name): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('observed');
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('dry-observed');
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
                return 'Observability test tool';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.observability.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'observability',
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
        // From tests/Integration/AgentRun/ → up 3 levels → repo root
        $migrationFile = \dirname(__DIR__, 3)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $migration->up(new SchemaBuilder($this->database->getConnection()));
    }
}

/**
 * Bridge that adapts Symfony's EventDispatcher to Waaseyaa's EventDispatcherInterface.
 */
final class SymfonyEventDispatcherBridge implements EventDispatcherInterface
{
    public function __construct(private readonly EventDispatcher $inner) {}

    public function dispatch(object $event, ?string $eventName = null): object
    {
        return $this->inner->dispatch($event);
    }

    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    public function removeListener(string $eventName, callable $listener): void
    {
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }
}

/**
 * Spy Telescope recorder that captures flushed records.
 */
final class SpyingTelescopeRecorder implements AgentTelescopeRecorderInterface
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function recordAgentRun(array $record): void
    {
        $this->records[] = $record;
    }
}

/**
 * Spy metrics recorder that captures Prometheus counters.
 */
final class SpyingMetricsRecorder implements AgentRunMetricsRecorderInterface
{
    /** @var list<array{status: string, agent_id: ?string, wall_clock_ms: ?int}> */
    public array $terminalRuns = [];

    /** @var list<array{provider: string, model: string, tokens_in: int, tokens_out: int}> */
    public array $providerTokens = [];

    public function recordTerminalRun(string $status, ?string $agentDefinitionId, ?int $wallClockMs): void
    {
        $this->terminalRuns[] = [
            'status' => $status,
            'agent_id' => $agentDefinitionId,
            'wall_clock_ms' => $wallClockMs,
        ];
    }

    public function recordProviderTokens(string $provider, string $model, int $tokensIn, int $tokensOut): void
    {
        $this->providerTokens[] = [
            'provider' => $provider,
            'model' => $model,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }
}
