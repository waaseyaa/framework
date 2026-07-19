<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Ai;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Reaper\StalledRunReaper;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\CLI\Command\Ai\AiReapStalledRunsCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(AiReapStalledRunsCommand::class)]
final class AiReapStalledRunsCommandTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = DBALDatabase::createSqlite();
        $migrationFile = \dirname(__DIR__, 6)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function stuck_running_row_is_reaped(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');
        $startedAt = $now->modify('-30 minutes');

        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();

        $run = $this->makeRun('stuck-1', RunStatus::Running, queuedAt: $startedAt, startedAt: $startedAt);
        $runRepo->save($run);

        $broadcaster = new RecordingBroadcaster();
        $reaper = new StalledRunReaper(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            broadcaster: $broadcaster,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $command = new AiReapStalledRunsCommand(
            reaper: $reaper,
            defaultMaxRuntimeSeconds: 600, // 10 min — well exceeded by the 30-min row.
        );

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Reaped 1 stalled runs.', $tester->getStdout());

        $loaded = $runRepo->find('stuck-1');
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Failed, $loaded->getStatus());
        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            static fn(...$args): AccessResult => AccessResult::allowed('System reaper verification projection.'),
        ));
        $principal = new AuthorizationPrincipal(0, true, ['system'], [], 'cli-agent-reaper-test');
        self::assertSame('worker_crashed', $scope->run(
            $principal,
            static fn(): mixed => $loaded->get('error_code'),
        ));
    }

    #[Test]
    public function terminal_row_is_left_untouched(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();

        $terminalRun = $this->makeRun(
            'done-1',
            RunStatus::Completed,
            queuedAt: $now->modify('-60 minutes'),
            startedAt: $now->modify('-30 minutes'),
        );
        $runRepo->save($terminalRun);

        $broadcaster = new RecordingBroadcaster();
        $reaper = new StalledRunReaper(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            broadcaster: $broadcaster,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $command = new AiReapStalledRunsCommand($reaper, defaultMaxRuntimeSeconds: 600);

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Reaped 0 stalled runs.', $tester->getStdout());

        $loaded = $runRepo->find('done-1');
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Completed, $loaded->getStatus(), 'terminal row must not regress (C-014).');
    }

    #[Test]
    public function idempotent_second_invocation_reaps_zero(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');
        $startedAt = $now->modify('-30 minutes');

        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();

        $run = $this->makeRun('stuck-2', RunStatus::Running, queuedAt: $startedAt, startedAt: $startedAt);
        $runRepo->save($run);

        $reaper = new StalledRunReaper(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            broadcaster: new RecordingBroadcaster(),
            now: static fn(): \DateTimeImmutable => $now,
        );

        $command = new AiReapStalledRunsCommand($reaper, defaultMaxRuntimeSeconds: 600);

        $first = $this->makeTester($command);
        $first->execute([]);
        self::assertStringContainsString('Reaped 1 stalled runs.', $first->getStdout());

        $second = $this->makeTester($command);
        $second->execute([]);
        self::assertStringContainsString('Reaped 0 stalled runs.', $second->getStdout());
    }

    #[Test]
    public function rejects_non_positive_max_runtime_seconds(): void
    {
        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();
        $reaper = new StalledRunReaper(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            broadcaster: new RecordingBroadcaster(),
        );

        $command = new AiReapStalledRunsCommand($reaper);
        $tester = $this->makeTester($command);
        $tester->execute(['--max-runtime-seconds=0']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('must be > 0', $tester->getStderr());
    }

    private function buildRunRepository(): AgentRunRepository
    {
        $entityType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return new AgentRunRepository($entityRepo, $this->database);
    }

    private function buildAuditRepository(): AgentAuditLogRepository
    {
        $entityType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log entry',
            class: \Waaseyaa\AI\Agent\Entity\AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return new AgentAuditLogRepository($entityRepo, $this->database);
    }

    private function makeRun(
        string $id,
        RunStatus $status,
        \DateTimeImmutable $queuedAt,
        ?\DateTimeImmutable $startedAt = null,
    ): AgentRun {
        $run = new AgentRun([
            'id' => $id,
            'account_id' => 0,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => $status->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'irrelevant',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => $queuedAt->format('Y-m-d H:i:s.uP'),
            'started_at' => $startedAt?->format('Y-m-d H:i:s.uP'),
            'finished_at' => $status === RunStatus::Completed || $status === RunStatus::Failed
                ? $queuedAt->format('Y-m-d H:i:s.uP')
                : null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        return $run;
    }

    private function makeTester(AiReapStalledRunsCommand $command): CliTester
    {
        return CliTester::for(
            $this->commandDefinition(),
            new class ($command) implements ContainerInterface {
                public function __construct(private readonly AiReapStalledRunsCommand $cmd) {}

                public function get(string $id): mixed
                {
                    if ($id === AiReapStalledRunsCommand::class) {
                        return $this->cmd;
                    }
                    throw new \RuntimeException("Not bound: {$id}");
                }

                public function has(string $id): bool
                {
                    return $id === AiReapStalledRunsCommand::class;
                }
            },
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'ai:reap-stalled-runs',
            description: 'Reap stalled AgentRun rows (FR-007, NFR-004).',
            options: [
                new HandlerOption(
                    name: 'max-runtime-seconds',
                    mode: HandlerOptionMode::Required,
                    default: '',
                ),
            ],
            handler: [AiReapStalledRunsCommand::class, 'execute'],
        );
    }
}

/**
 * @internal
 */
final class RecordingBroadcaster implements AgentRunBroadcasterInterface
{
    /** @var list<array{run_id: string, event: string, data: array<string, mixed>}> */
    public array $pushed = [];

    public function push(string $runId, string $event, array $data): void
    {
        $this->pushed[] = ['run_id' => $runId, 'event' => $event, 'data' => $data];
    }
}
