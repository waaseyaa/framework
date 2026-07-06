<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Ai;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\CLI\Command\Ai\AiPurgeRunsCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Unit test for {@see AiPurgeRunsCommand}.
 *
 * We use an in-memory SQLite database with the real migration so we
 * exercise the canonical persistence pipeline (entity-storage invariant)
 * without standing up the full kernel. This keeps the test fast while
 * giving us real `AgentRunRepository::findOldByQueuedAt()` and
 * `AgentAuditLogRepository::purgeOlderThan()` semantics.
 */
#[CoversClass(AiPurgeRunsCommand::class)]
final class AiPurgeRunsCommandTest extends TestCase
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

    #[Test]
    public function rows_past_retention_are_deleted_and_inside_retention_preserved(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        [$runRepo, $entityRepo] = $this->buildRunRepository();
        [$auditRepo, $auditEntityRepo] = $this->buildAuditRepository();

        // Insert: one old run (40 days back), one fresh run (1 day back).
        $oldRun = $this->makeRun('old-run', queuedAt: $now->modify('-40 days'));
        $freshRun = $this->makeRun('fresh-run', queuedAt: $now->modify('-1 day'));
        $runRepo->save($oldRun);
        $runRepo->save($freshRun);

        // Old audit row (40 days back) and recent audit row (1 day back).
        $auditRepo->append(AgentAuditLog::for(
            id: 'audit-old',
            runId: 'old-run',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $now->modify('-40 days'),
        ));
        $auditRepo->append(AgentAuditLog::for(
            id: 'audit-fresh',
            runId: 'fresh-run',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $now->modify('-1 day'),
        ));

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $entityRepo,
            defaultRetentionDays: 30,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Deleted 1 runs and 1 audit rows.', $tester->getStdout());

        // Old run gone, fresh run preserved.
        self::assertNull($runRepo->find('old-run'));
        self::assertNotNull($runRepo->find('fresh-run'));

        // Audit: fresh row preserved.
        $rows = $auditRepo->findByRunId('fresh-run');
        self::assertCount(1, $rows);
    }

    #[Test]
    public function custom_retention_days_overrides_default(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        [$runRepo, $entityRepo] = $this->buildRunRepository();
        [$auditRepo] = $this->buildAuditRepository();

        // 10-day-old run; default retention (30) would preserve it.
        $tenDayRun = $this->makeRun('ten-day', queuedAt: $now->modify('-10 days'));
        $runRepo->save($tenDayRun);

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $entityRepo,
            defaultRetentionDays: 30,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $tester = $this->makeTester($command);
        // Tighter retention: 7 days. The 10-day-old run should be purged.
        $tester->execute(['--retention-days=7']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertNull($runRepo->find('ten-day'));
        self::assertStringContainsString('Deleted 1 runs', $tester->getStdout());
    }

    #[Test]
    public function over_age_non_terminal_runs_survive_purge(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        [$runRepo, $entityRepo] = $this->buildRunRepository();
        [$auditRepo] = $this->buildAuditRepository();

        // All are 40 days past the 30-day default retention threshold, but
        // none is in a terminal status: `queued` never started,
        // `awaiting_approval` waits on a human, and `running`/`cancelling`
        // are the reaper's responsibility. Age alone must not delete a run
        // the reaper has not classified as dead. Derive the non-terminal
        // set from the enum so a future case is covered automatically.
        $nonTerminals = array_values(array_filter(
            RunStatus::cases(),
            static fn(RunStatus $status): bool => !$status->isTerminal(),
        ));
        self::assertNotEmpty($nonTerminals);

        foreach ($nonTerminals as $status) {
            $run = $this->makeRun('still-' . $status->value, queuedAt: $now->modify('-40 days'), status: $status);
            $runRepo->save($run);
        }

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $entityRepo,
            defaultRetentionDays: 30,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Deleted 0 runs', $tester->getStdout());
        foreach ($nonTerminals as $status) {
            self::assertNotNull(
                $runRepo->find('still-' . $status->value),
                \sprintf('Expected non-terminal status "%s" to survive the purge.', $status->value),
            );
        }
    }

    /**
     * @return array<string, array{RunStatus}>
     */
    public static function terminalStatusProvider(): array
    {
        $cases = [];
        foreach (RunStatus::terminals() as $status) {
            $cases[$status->value] = [$status];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('terminalStatusProvider')]
    public function over_age_run_in_terminal_status_is_purged(RunStatus $status): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        [$runRepo, $entityRepo] = $this->buildRunRepository();
        [$auditRepo] = $this->buildAuditRepository();

        $id = 'terminal-' . $status->value;
        $run = $this->makeRun($id, queuedAt: $now->modify('-40 days'), status: $status);
        $runRepo->save($run);

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $entityRepo,
            defaultRetentionDays: 30,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Deleted 1 runs', $tester->getStdout());
        self::assertNull($runRepo->find($id), \sprintf('Expected terminal status "%s" to be purged.', $status->value));
    }

    #[Test]
    public function rejects_non_positive_retention_days(): void
    {
        [$runRepo, $entityRepo] = $this->buildRunRepository();
        [$auditRepo] = $this->buildAuditRepository();

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $entityRepo,
            defaultRetentionDays: 30,
        );

        $tester = $this->makeTester($command);
        $tester->execute(['--retention-days=0']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('must be > 0', $tester->getStderr());
    }

    /**
     * @return array{0: AgentRunRepository, 1: EntityRepository}
     */
    private function buildRunRepository(): array
    {
        $entityType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return [new AgentRunRepository($entityRepo, $this->database), $entityRepo];
    }

    /**
     * @return array{0: AgentAuditLogRepository, 1: EntityRepository}
     */
    private function buildAuditRepository(): array
    {
        $entityType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log entry',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return [new AgentAuditLogRepository($entityRepo, $this->database), $entityRepo];
    }

    private function makeRun(string $id, \DateTimeImmutable $queuedAt, RunStatus $status = RunStatus::Completed): AgentRun
    {
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
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        return $run;
    }

    private function makeTester(AiPurgeRunsCommand $command): CliTester
    {
        return CliTester::for(
            $this->commandDefinition(),
            new class($command) implements ContainerInterface {
                public function __construct(private readonly AiPurgeRunsCommand $cmd) {}

                public function get(string $id): mixed
                {
                    if ($id === AiPurgeRunsCommand::class) {
                        return $this->cmd;
                    }
                    throw new \RuntimeException("Not bound: {$id}");
                }

                public function has(string $id): bool
                {
                    return $id === AiPurgeRunsCommand::class;
                }
            },
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'ai:purge-runs',
            description: 'Purge old AgentRun + AgentAuditLog rows (FR-006).',
            options: [
                new HandlerOption(
                    name: 'retention-days',
                    mode: HandlerOptionMode::Required,
                    default: '',
                ),
            ],
            handler: [AiPurgeRunsCommand::class, 'execute'],
        );
    }
}
