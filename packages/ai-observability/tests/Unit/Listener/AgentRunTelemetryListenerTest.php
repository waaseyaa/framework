<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunIterationCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunProviderCallCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunStarted;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved;
use Waaseyaa\AI\Observability\Listener\AgentRunTelemetryListener;
use Waaseyaa\AI\Observability\Recorder\AgentRunMetricsRecorderInterface;
use Waaseyaa\AI\Observability\Recorder\AgentTelescopeRecorderInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;

#[CoversClass(AgentRunTelemetryListener::class)]
final class AgentRunTelemetryListenerTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = DBALDatabase::createSqlite();

        $migrationFile = \dirname(__DIR__, 4) . '/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof \Waaseyaa\Foundation\Migration\Migration);
        $schema = new \Waaseyaa\Foundation\Migration\SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        $entityType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->database), 'id');
        $entityRepo = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );
        $this->runRepository = new AgentRunRepository($entityRepo, $this->database);
    }

    #[Test]
    public function singleRunLifecycleProducesSingleTelescopeRecord(): void
    {
        $this->saveQueuedRun('run-1', accountId: 7, agentDefinitionId: 'agent-research');

        $telescope = new RecordingTelescopeRecorder();
        $metrics = new RecordingMetricsRecorder();
        $listener = new AgentRunTelemetryListener(
            telescope: $telescope,
            runRepository: $this->runRepository,
            metrics: $metrics,
        );

        $startedAt = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');
        $finishedAt = new \DateTimeImmutable('2026-05-18T12:00:05+00:00');

        $listener->onRunStarted(new AgentRunStarted(
            runId: 'run-1',
            agentDefinitionId: 'agent-research',
            accountId: 7,
            startedAt: $startedAt,
        ));

        $listener->onIterationCompleted(new AgentRunIterationCompleted('run-1', 0, 1200));
        $listener->onIterationCompleted(new AgentRunIterationCompleted('run-1', 1, 800));

        $listener->onProviderCallCompleted(new AgentRunProviderCallCompleted(
            runId: 'run-1',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            tokensIn: 1_000_000,
            tokensOut: 200_000,
        ));

        $listener->onToolCallObserved(new AgentRunToolCallObserved('run-1', 'search', true));

        $listener->onRunTerminated(new AgentRunTerminated(
            runId: 'run-1',
            status: 'completed',
            errorCode: null,
            finishedAt: $finishedAt,
        ));

        self::assertCount(1, $telescope->records, 'exactly one record per terminal status');
        $record = $telescope->records[0];

        self::assertSame('run-1', $record['run_id']);
        self::assertSame('agent-research', $record['agent_definition_id']);
        self::assertSame(7, $record['account_id']);
        self::assertSame(1_000_000, $record['tokens_in']);
        self::assertSame(200_000, $record['tokens_out']);
        self::assertSame(600, $record['cost_cents']);
        self::assertSame(1, $record['tool_call_count']);
        self::assertSame([1200, 800], $record['iteration_durations_ms']);
        self::assertSame(5000, $record['wall_clock_ms']);
        self::assertSame('completed', $record['status']);
        self::assertNull($record['error_code']);
        self::assertSame(
            $startedAt->format(\DateTimeInterface::ATOM),
            $record['started_at'],
        );
        self::assertSame(
            $finishedAt->format(\DateTimeInterface::ATOM),
            $record['finished_at'],
        );

        // AgentRun row populated.
        $persisted = $this->runRepository->find('run-1');
        self::assertNotNull($persisted);
        self::assertSame(1_000_000, (int) $persisted->get('token_usage_in'));
        self::assertSame(200_000, (int) $persisted->get('token_usage_out'));
        self::assertSame(600, (int) $persisted->get('cost_cents'));
        self::assertSame(1, (int) $persisted->get('tool_call_count'));

        // Metrics.
        self::assertCount(1, $metrics->terminalRuns);
        self::assertSame('completed', $metrics->terminalRuns[0]['status']);
        self::assertSame(5000, $metrics->terminalRuns[0]['wall_clock_ms']);
        self::assertCount(1, $metrics->providerTokens);
        self::assertSame('anthropic', $metrics->providerTokens[0]['provider']);
    }

    #[Test]
    public function unknownProviderModelLeavesCostCentsNull(): void
    {
        $this->saveQueuedRun('run-2', accountId: 7, agentDefinitionId: null);

        $telescope = new RecordingTelescopeRecorder();
        $listener = new AgentRunTelemetryListener(
            telescope: $telescope,
            runRepository: $this->runRepository,
        );

        $listener->onRunStarted(new AgentRunStarted(
            runId: 'run-2',
            agentDefinitionId: null,
            accountId: 7,
            startedAt: new \DateTimeImmutable('2026-05-18T12:00:00+00:00'),
        ));
        $listener->onProviderCallCompleted(new AgentRunProviderCallCompleted(
            runId: 'run-2',
            provider: 'unknown',
            model: 'unmapped',
            tokensIn: 1000,
            tokensOut: 500,
        ));
        $listener->onRunTerminated(new AgentRunTerminated(
            runId: 'run-2',
            status: 'completed',
            errorCode: null,
            finishedAt: new \DateTimeImmutable('2026-05-18T12:00:01+00:00'),
        ));

        $record = $telescope->records[0];
        self::assertSame(1000, $record['tokens_in']);
        self::assertSame(500, $record['tokens_out']);
        self::assertNull($record['cost_cents'], 'unknown provider:model leaves cost null');
    }

    #[Test]
    public function telescopeRecorderThrowingDoesNotPropagateToCaller(): void
    {
        $this->saveQueuedRun('run-3', accountId: 1, agentDefinitionId: null);

        $telescope = new ThrowingTelescopeRecorder();
        $logger = new RecordingLogger();

        $listener = new AgentRunTelemetryListener(
            telescope: $telescope,
            runRepository: $this->runRepository,
            logger: $logger,
        );

        $listener->onRunStarted(new AgentRunStarted(
            runId: 'run-3',
            agentDefinitionId: null,
            accountId: 1,
            startedAt: new \DateTimeImmutable('2026-05-18T12:00:00+00:00'),
        ));

        // MUST NOT throw.
        $listener->onRunTerminated(new AgentRunTerminated(
            runId: 'run-3',
            status: 'failed',
            errorCode: 'provider_error',
            finishedAt: new \DateTimeImmutable('2026-05-18T12:00:01+00:00'),
        ));

        self::assertNotEmpty(
            $logger->messages,
            'listener must log the swallowed exception via LoggerInterface',
        );

        $hasTelescopeError = false;
        foreach ($logger->messages as $entry) {
            if (\str_contains($entry['message'], 'Telescope recordAgentRun failed')) {
                $hasTelescopeError = true;
                break;
            }
        }
        self::assertTrue($hasTelescopeError);
    }

    #[Test]
    public function metricsRecorderThrowingDoesNotPropagateToCaller(): void
    {
        // The metrics recorder is dispatched best-effort inside the
        // provider-call handler. A throw must be caught + logged.
        $logger = new RecordingLogger();
        $listener = new AgentRunTelemetryListener(
            telescope: new RecordingTelescopeRecorder(),
            runRepository: $this->runRepository,
            metrics: new ThrowingMetricsRecorder(),
            logger: $logger,
        );

        // No throw — handler wrapper isolates the metrics fault.
        $listener->onProviderCallCompleted(new AgentRunProviderCallCompleted(
            runId: 'run-4',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            tokensIn: 100,
            tokensOut: 100,
        ));

        self::assertNotEmpty($logger->messages);
        $sawMetricsWarning = false;
        foreach ($logger->messages as $entry) {
            if (\str_contains($entry['message'], 'provider-token metric emit failed')) {
                $sawMetricsWarning = true;
                break;
            }
        }
        self::assertTrue($sawMetricsWarning);
    }

    #[Test]
    public function startingTheNextRunDropsInterruptedRunStateInTheSameWorker(): void
    {
        $this->saveQueuedRun('run-a', accountId: 1, agentDefinitionId: 'first-agent');
        $this->saveQueuedRun('run-b', accountId: 2, agentDefinitionId: 'second-agent');
        $telescope = new RecordingTelescopeRecorder();
        $listener = new AgentRunTelemetryListener(
            telescope: $telescope,
            runRepository: $this->runRepository,
        );

        $listener->onRunStarted(new AgentRunStarted(
            runId: 'run-a',
            agentDefinitionId: 'first-agent',
            accountId: 1,
            startedAt: new \DateTimeImmutable('2026-05-18T12:00:00+00:00'),
        ));
        $listener->onProviderCallCompleted(new AgentRunProviderCallCompleted(
            runId: 'run-a',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            tokensIn: 100,
            tokensOut: 50,
        ));

        $listener->onRunStarted(new AgentRunStarted(
            runId: 'run-b',
            agentDefinitionId: 'second-agent',
            accountId: 2,
            startedAt: new \DateTimeImmutable('2026-05-18T12:01:00+00:00'),
        ));
        $listener->onRunTerminated(new AgentRunTerminated(
            runId: 'run-b',
            status: 'completed',
            errorCode: null,
            finishedAt: new \DateTimeImmutable('2026-05-18T12:01:01+00:00'),
        ));

        // A late terminal event for the interrupted request must not recover
        // aggregates retained from before the next worker message started.
        $listener->onRunTerminated(new AgentRunTerminated(
            runId: 'run-a',
            status: 'failed',
            errorCode: 'worker_interrupted',
            finishedAt: new \DateTimeImmutable('2026-05-18T12:02:00+00:00'),
        ));

        self::assertCount(2, $telescope->records);
        self::assertSame('run-a', $telescope->records[1]['run_id']);
        self::assertNull($telescope->records[1]['agent_definition_id']);
        self::assertSame(0, $telescope->records[1]['tokens_in']);
        self::assertSame(0, $telescope->records[1]['tokens_out']);
    }

    #[Test]
    public function getSubscribedEventsCoversFiveEvents(): void
    {
        $map = AgentRunTelemetryListener::getSubscribedEvents();
        self::assertArrayHasKey(AgentRunStarted::class, $map);
        self::assertArrayHasKey(AgentRunIterationCompleted::class, $map);
        self::assertArrayHasKey(AgentRunProviderCallCompleted::class, $map);
        self::assertArrayHasKey(AgentRunToolCallObserved::class, $map);
        self::assertArrayHasKey(AgentRunTerminated::class, $map);
    }

    private function saveQueuedRun(string $id, int $accountId, ?string $agentDefinitionId): void
    {
        $run = new AgentRun([
            'id' => $id,
            'account_id' => $accountId,
            'agent_definition_id' => $agentDefinitionId,
            'bundle_json' => '{}',
            'status' => RunStatus::Queued->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'noop',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => '2026-05-18 11:30:00.000000+00:00',
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);
    }
}

/**
 * Test double — captures Telescope calls in-memory.
 */
final class RecordingTelescopeRecorder implements AgentTelescopeRecorderInterface
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function recordAgentRun(array $record): void
    {
        $this->records[] = $record;
    }
}

final class ThrowingTelescopeRecorder implements AgentTelescopeRecorderInterface
{
    public function recordAgentRun(array $record): void
    {
        throw new \RuntimeException('telescope unreachable');
    }
}

final class ThrowingMetricsRecorder implements AgentRunMetricsRecorderInterface
{
    public function recordTerminalRun(string $status, ?string $agentDefinitionId, ?int $wallClockMs): void
    {
        throw new \RuntimeException('prometheus unreachable');
    }

    public function recordProviderTokens(string $provider, string $model, int $tokensIn, int $tokensOut): void
    {
        throw new \RuntimeException('prometheus unreachable');
    }
}

final class RecordingMetricsRecorder implements AgentRunMetricsRecorderInterface
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

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $messages = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = [
            'level' => $level->value,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
