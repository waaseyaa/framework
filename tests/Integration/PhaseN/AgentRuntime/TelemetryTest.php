<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
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
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * FR-029 integration smoke test for AgentRun telemetry.
 *
 * Wires the real {@see AgentRunTelemetryListener} against a SQLite-backed
 * {@see AgentRunRepository}, dispatches the lifecycle events through a
 * Symfony EventDispatcher, and verifies:
 *
 *  - exactly one Telescope record per terminal status;
 *  - all documented fields populated;
 *  - Prometheus counters increment via the metrics recorder;
 *  - `AgentRun.token_usage_in/out`, `cost_cents`, `tool_call_count`
 *    are persisted to the database row at terminal-status flush.
 */
#[CoversNothing]
final class TelemetryTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private EventDispatcher $dispatcher;
    private RecordingTelescopeRecorder $telescope;
    private RecordingMetricsRecorder $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $migration->up(new SchemaBuilder($this->database->getConnection()));

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

        $this->telescope = new RecordingTelescopeRecorder();
        $this->metrics = new RecordingMetricsRecorder();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new AgentRunTelemetryListener(
            telescope: $this->telescope,
            runRepository: $this->runRepository,
            metrics: $this->metrics,
        ));
    }

    #[Test]
    public function endToEndRunProducesTelescopeRecordPrometheusAndAgentRowFields(): void
    {
        $this->saveQueuedRun('run-int-1', accountId: 11, agentDefinitionId: 'agent-test');

        $startedAt = new \DateTimeImmutable('2026-05-18T10:00:00+00:00');
        $finishedAt = new \DateTimeImmutable('2026-05-18T10:00:07+00:00');

        $this->dispatcher->dispatch(new AgentRunStarted(
            runId: 'run-int-1',
            agentDefinitionId: 'agent-test',
            accountId: 11,
            startedAt: $startedAt,
        ));
        $this->dispatcher->dispatch(new AgentRunIterationCompleted('run-int-1', 0, 1500));
        $this->dispatcher->dispatch(new AgentRunIterationCompleted('run-int-1', 1, 700));
        $this->dispatcher->dispatch(new AgentRunProviderCallCompleted(
            runId: 'run-int-1',
            provider: 'anthropic',
            model: 'claude-opus-4-7',
            tokensIn: 2_000_000,
            tokensOut: 500_000,
        ));
        $this->dispatcher->dispatch(new AgentRunToolCallObserved('run-int-1', 'search', true));
        $this->dispatcher->dispatch(new AgentRunToolCallObserved('run-int-1', 'fetch', true));
        $this->dispatcher->dispatch(new AgentRunTerminated(
            runId: 'run-int-1',
            status: 'completed',
            errorCode: null,
            finishedAt: $finishedAt,
        ));

        // Telescope record: shape + fields.
        self::assertCount(1, $this->telescope->records);
        $record = $this->telescope->records[0];

        foreach (
            [
                'run_id', 'agent_definition_id', 'account_id',
                'tokens_in', 'tokens_out', 'cost_cents',
                'tool_call_count',
                'wall_clock_ms', 'iteration_durations_ms',
                'status', 'error_code',
                'started_at', 'finished_at',
            ] as $expectedKey
        ) {
            self::assertArrayHasKey($expectedKey, $record, "missing field: {$expectedKey}");
        }

        self::assertSame(2_000_000, $record['tokens_in']);
        self::assertSame(500_000, $record['tokens_out']);
        // Opus: 2M * 1500 + 500_000 * 7500 = 3_000_000_000 + 3_750_000_000 = 6_750_000_000
        // /1_000_000 = 6750.
        self::assertSame(6750, $record['cost_cents']);
        self::assertSame(2, $record['tool_call_count']);
        self::assertSame([1500, 700], $record['iteration_durations_ms']);
        self::assertSame('completed', $record['status']);

        // Prometheus counters.
        self::assertCount(1, $this->metrics->terminalRuns);
        self::assertSame('completed', $this->metrics->terminalRuns[0]['status']);
        self::assertCount(1, $this->metrics->providerTokens);
        self::assertSame('anthropic', $this->metrics->providerTokens[0]['provider']);

        // AgentRun row updated.
        $row = $this->runRepository->find('run-int-1');
        self::assertNotNull($row);
        $projection = new \Waaseyaa\Tests\Support\AgentRunAccountProjectionReaderFixture()->readWithoutAccount($row);
        self::assertSame(2_000_000, $projection->tokenUsageIn);
        self::assertSame(500_000, $projection->tokenUsageOut);
        self::assertSame(6750, $projection->costCents);
        self::assertSame(2, $projection->toolCallCount);
    }

    #[Test]
    public function failedTerminalStatusPropagatesErrorCodeToTelescope(): void
    {
        $this->saveQueuedRun('run-int-2', accountId: 1, agentDefinitionId: null);

        $this->dispatcher->dispatch(new AgentRunStarted(
            runId: 'run-int-2',
            agentDefinitionId: null,
            accountId: 1,
            startedAt: new \DateTimeImmutable('2026-05-18T10:00:00+00:00'),
        ));
        $this->dispatcher->dispatch(new AgentRunTerminated(
            runId: 'run-int-2',
            status: 'failed',
            errorCode: 'provider_unreachable',
            finishedAt: new \DateTimeImmutable('2026-05-18T10:00:02+00:00'),
        ));

        self::assertCount(1, $this->telescope->records);
        self::assertSame('failed', $this->telescope->records[0]['status']);
        self::assertSame('provider_unreachable', $this->telescope->records[0]['error_code']);
        self::assertSame('failed', $this->metrics->terminalRuns[0]['status']);
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
            'queued_at' => '2026-05-18 09:00:00.000000+00:00',
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);
    }
}

final class RecordingTelescopeRecorder implements AgentTelescopeRecorderInterface
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function recordAgentRun(array $record): void
    {
        $this->records[] = $record;
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
