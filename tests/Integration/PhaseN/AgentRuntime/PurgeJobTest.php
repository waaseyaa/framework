<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
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
 * End-to-end test for `bin/waaseyaa ai:purge-runs` (FR-006).
 *
 * Boots the canonical persistence pipeline against in-memory SQLite,
 * seeds a mix of old and fresh `agent_run` + `agent_audit_log` rows,
 * runs the CLI command and asserts the retention sweep deletes only
 * rows past the threshold.
 *
 * @api
 */
#[CoversNothing]
final class PurgeJobTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = DBALDatabase::createSqlite();
        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }

    #[Test]
    public function purge_deletes_old_runs_and_old_audit_rows_in_lockstep(): void
    {
        $now = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        [$runRepo, $runEntityRepo] = $this->buildRunRepository();
        [$auditRepo] = $this->buildAuditRepository();

        // Seed: two old runs, two fresh runs.
        $runRepo->save($this->makeRun('old-a', $now->modify('-40 days')));
        $runRepo->save($this->makeRun('old-b', $now->modify('-50 days')));
        $runRepo->save($this->makeRun('fresh-a', $now->modify('-1 day')));
        $runRepo->save($this->makeRun('fresh-b', $now->modify('-5 days')));

        // Seed audit: matching old + fresh entries.
        $auditRepo->append(AgentAuditLog::for(
            id: 'audit-old-1',
            runId: 'old-a',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $now->modify('-40 days'),
        ));
        $auditRepo->append(AgentAuditLog::for(
            id: 'audit-old-2',
            runId: 'old-b',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $now->modify('-50 days'),
        ));
        $auditRepo->append(AgentAuditLog::for(
            id: 'audit-fresh-1',
            runId: 'fresh-a',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $now->modify('-1 day'),
        ));

        $command = new AiPurgeRunsCommand(
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            runEntityRepository: $runEntityRepo,
            defaultRetentionDays: 30,
            now: static fn(): \DateTimeImmutable => $now,
        );

        $tester = $this->makeTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Deleted 2 runs and 2 audit rows.', $tester->getStdout());

        // Old runs gone.
        self::assertNull($runRepo->find('old-a'));
        self::assertNull($runRepo->find('old-b'));
        // Fresh runs preserved.
        self::assertNotNull($runRepo->find('fresh-a'));
        self::assertNotNull($runRepo->find('fresh-b'));

        // Fresh audit row preserved.
        $rows = $auditRepo->findByRunId('fresh-a');
        self::assertCount(1, $rows);
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
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
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
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return [new AgentAuditLogRepository($entityRepo, $this->database), $entityRepo];
    }

    private function makeRun(string $id, \DateTimeImmutable $queuedAt): AgentRun
    {
        $run = new AgentRun([
            'id' => $id,
            'account_id' => 0,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => RunStatus::Completed->value,
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
            'finished_at' => $queuedAt->format('Y-m-d H:i:s.uP'),
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
            new class ($command) implements ContainerInterface {
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
            description: 'Purge old AgentRun + AgentAuditLog rows.',
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
