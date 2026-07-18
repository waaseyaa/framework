<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessStatus;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * End-to-end persistence smoke test for the agent-executor entity surface.
 *
 * Wires the canonical pipeline from `.claude/rules/entity-storage-invariant.md`:
 *
 *   Entity → EntityType → SqlStorageDriver → EntityRepository → DatabaseInterface
 *
 * Asserts:
 *   - The schema migration runs cleanly on SQLite.
 *   - An `AgentRun` round-trips through {@see AgentRunRepository}.
 *   - An `AgentAuditLog` round-trips through {@see AgentAuditLogRepository}.
 *   - Compare-and-swap status transitions enforce C-014 across two
 *     repository instances sharing the same database.
 *   - {@see AgentRunAccessPolicy} denies a non-owner without the
 *     bypass-ownership permission.
 *
 * @api
 */
#[CoversNothing]
final class EntityPersistenceTest extends TestCase
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
    public function agentRunRoundTripsThroughTheCanonicalPipeline(): void
    {
        $repository = $this->buildRunRepository();

        $run = $this->makeQueuedRun('run-1', accountId: 42);
        $repository->save($run);

        $loaded = $repository->find('run-1');
        self::assertNotNull($loaded);
        $worker = new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture();
        self::assertSame(42, $worker->read($loaded)->accountId);
        self::assertSame(RunStatus::Queued, $loaded->getStatus());
        self::assertSame('hello agent', $worker->read($loaded)->prompt);
    }

    #[Test]
    public function statusTransitionsAreCompareAndSwapAcrossInstances(): void
    {
        $repoA = $this->buildRunRepository();
        $repoB = $this->buildRunRepository();

        $run = $this->makeQueuedRun('run-2', accountId: 1);
        $repoA->save($run);

        $started = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        // First worker wins.
        self::assertTrue($repoA->markRunning('run-2', $started));
        // Concurrent worker via a different repository instance is rejected.
        self::assertFalse($repoB->markRunning('run-2', $started));

        $finished = new \DateTimeImmutable('2026-05-18T12:01:00+00:00');
        self::assertTrue($repoA->markTerminal('run-2', RunStatus::Completed, $finished));
        // C-014: cannot overwrite a terminal status from another worker.
        self::assertFalse($repoB->markTerminal('run-2', RunStatus::Failed, $finished, 'late', 'late'));
    }

    #[Test]
    public function auditLogRowsAppendAndReplayInOrder(): void
    {
        $auditRepo = $this->buildAuditRepository();
        $base = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        $auditRepo->append(AgentAuditLog::for(
            id: 'evt-1',
            runId: 'run-3',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $base,
        ));
        $auditRepo->append(AgentAuditLog::for(
            id: 'evt-2',
            runId: 'run-3',
            iteration: 0,
            eventType: EventType::ProviderCall,
            occurredAt: $base->modify('+2 seconds'),
            durationMs: 412,
        ));

        $rows = $auditRepo->findByRunId('run-3');
        self::assertCount(2, $rows);
        $reader = new \Waaseyaa\Tests\Support\AgentAuditEventTypeReaderFixture();
        self::assertSame(EventType::IterationStart, $reader->read($rows[0]));
        self::assertSame(EventType::ProviderCall, $reader->read($rows[1]));
    }

    #[Test]
    public function accessPolicyAllowsOwnerAndDeniesStranger(): void
    {
        $runRepo = $this->buildRunRepository();
        $policy = new AgentRunAccessPolicy($runRepo);

        $run = $this->makeQueuedRun('run-4', accountId: 7);
        $runRepo->save($run);
        $loaded = $runRepo->find('run-4');
        self::assertNotNull($loaded);

        $owner = $this->makeAccount(id: 7);
        $stranger = $this->makeAccount(id: 99);
        $admin = $this->makeAccount(id: 99, permissions: ['agent.run.bypass_ownership']);

        self::assertSame(AccessStatus::ALLOWED, $policy->access($loaded, 'view', $owner)->status);
        self::assertSame(AccessStatus::NEUTRAL, $policy->access($loaded, 'view', $stranger)->status);
        self::assertSame(AccessStatus::ALLOWED, $policy->access($loaded, 'delete', $admin)->status);
    }

    #[Test]
    public function accessPolicyResolvesAuditLogParentRun(): void
    {
        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();
        $policy = new AgentRunAccessPolicy($runRepo);

        $run = $this->makeQueuedRun('run-5', accountId: 11);
        $runRepo->save($run);

        $log = AgentAuditLog::for(
            id: 'evt-5',
            runId: 'run-5',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: new \DateTimeImmutable(),
        );
        $auditRepo->append($log);

        $rows = $auditRepo->findByRunId('run-5');
        self::assertCount(1, $rows);

        $owner = $this->makeAccount(id: 11);
        $stranger = $this->makeAccount(id: 12);

        self::assertSame(AccessStatus::ALLOWED, $policy->access($rows[0], 'view', $owner)->status);
        self::assertSame(AccessStatus::NEUTRAL, $policy->access($rows[0], 'view', $stranger)->status);
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
        $entityRepo = new EntityRepository(
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

        return new AgentAuditLogRepository($entityRepo, $this->database);
    }

    private function makeQueuedRun(string $id, int $accountId): AgentRun
    {
        $run = new AgentRun([
            'id' => $id,
            'account_id' => $accountId,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => RunStatus::Queued->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'hello agent',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => new \DateTimeImmutable('2026-05-18T11:30:00+00:00')->format('Y-m-d H:i:s.uP'),
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);

        return $run;
    }

    /**
     * @param list<string> $permissions
     */
    private function makeAccount(int $id, array $permissions = []): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            /**
             * @param list<string> $permissions
             */
            public function __construct(
                private readonly int $accountId,
                private readonly array $permissions,
            ) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, $this->permissions, strict: true);
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
