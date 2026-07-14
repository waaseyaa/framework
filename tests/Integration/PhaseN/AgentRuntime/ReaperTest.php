<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Reaper\StalledRunReaper;
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
 * Integration test for {@see StalledRunReaper} (NFR-004, FR-007, C-014).
 *
 * Asserts:
 *
 *  - A `running` row with `started_at` older than the threshold is
 *    flipped to `failed/worker_crashed`, an `error` audit row appears,
 *    and a `run_failed` SSE event is emitted.
 *  - A `running` row inside the threshold is left untouched.
 *  - A terminal row is never regressed by the reaper — `markTerminal`'s
 *    CAS clause refuses the transition, the reap loop skips silently.
 *
 * @api
 */
#[CoversNothing]
final class ReaperTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;

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

        $this->runRepository = $this->buildRunRepository();
        $this->auditRepository = $this->buildAuditRepository();
    }

    #[Test]
    public function reapFlipsStalledRowsToFailedWorkerCrashed(): void
    {
        $this->seedRunningRun('run-stuck', startedSecondsAgo: 700);
        $this->seedRunningRun('run-fresh', startedSecondsAgo: 60);

        $broadcaster = new ReaperCapturingBroadcaster();
        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: $broadcaster,
        );

        $flipped = $reaper->reap(maxRuntimeSeconds: 600);

        self::assertSame(1, $flipped, 'Only the stalled run should be flipped.');

        $stuck = $this->runRepository->find('run-stuck');
        self::assertNotNull($stuck);
        self::assertSame(RunStatus::Failed, $stuck->getStatus());
        self::assertSame('worker_crashed', $stuck->get('error_code'));

        $fresh = $this->runRepository->find('run-fresh');
        self::assertNotNull($fresh);
        self::assertSame(RunStatus::Running, $fresh->getStatus(), 'Within-threshold run must be left running.');

        // Audit row was appended.
        $audit = $this->auditRepository->findByRunId('run-stuck');
        self::assertNotEmpty($audit);
        self::assertSame(EventType::Error, $audit[0]->getEventType());
        self::assertSame('worker_crashed', $audit[0]->get('tool_result_summary'));

        // SSE event was emitted.
        self::assertContains('run_failed', $broadcaster->eventsFor('run-stuck'));
        self::assertSame([], $broadcaster->eventsFor('run-fresh'));
    }

    #[Test]
    public function reapNeverRegressesAlreadyTerminalRows(): void
    {
        // Stalled row that has already been flipped to terminal by the
        // worker just before the reaper runs.
        $this->seedRunningRun('run-raced', startedSecondsAgo: 1000);
        $finished = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');
        self::assertTrue($this->runRepository->markTerminal('run-raced', RunStatus::Completed, $finished));

        $broadcaster = new ReaperCapturingBroadcaster();
        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: $broadcaster,
        );

        $flipped = $reaper->reap(maxRuntimeSeconds: 600);

        // findStuckRunning() filters on status='running', so the row
        // doesn't surface at all — reaper count is 0.
        self::assertSame(0, $flipped);
        $reloaded = $this->runRepository->find('run-raced');
        self::assertNotNull($reloaded);
        self::assertSame(RunStatus::Completed, $reloaded->getStatus(), 'Terminal status must not regress.');
        self::assertNull($reloaded->get('error_code'));
    }

    #[Test]
    public function reapTerminalizesAbandonedQueuedApprovalAndCancellationRows(): void
    {
        $this->seedRun('run-queued', RunStatus::Queued, ageSeconds: 700);
        $this->seedRun('run-approval', RunStatus::AwaitingApproval, ageSeconds: 700);
        $this->seedRun('run-cancelling', RunStatus::Cancelling, ageSeconds: 700);
        $this->seedRun('run-fresh-queued', RunStatus::Queued, ageSeconds: 60);

        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: new ReaperCapturingBroadcaster(),
        );

        self::assertSame(3, $reaper->reap(maxRuntimeSeconds: 600));
        self::assertSame(RunStatus::Failed, $this->runRepository->find('run-queued')?->getStatus());
        self::assertSame('queue_timeout', $this->runRepository->find('run-queued')?->get('error_code'));
        self::assertSame(RunStatus::Failed, $this->runRepository->find('run-approval')?->getStatus());
        self::assertSame('approval_timeout', $this->runRepository->find('run-approval')?->get('error_code'));
        self::assertNull($this->runRepository->find('run-approval')?->get('pending_approval_call_id'));
        self::assertNull($this->runRepository->find('run-approval')?->get('approval_expires_at'));
        self::assertSame(RunStatus::Cancelled, $this->runRepository->find('run-cancelling')?->getStatus());
        self::assertSame('cancellation_timeout', $this->runRepository->find('run-cancelling')?->get('error_code'));
        self::assertSame(RunStatus::Queued, $this->runRepository->find('run-fresh-queued')?->getStatus());
    }

    #[Test]
    public function selectedQueuedCandidateCannotKillAWorkerClaimedBeforeTerminalCas(): void
    {
        $now = new \DateTimeImmutable('now');
        $this->seedRun('run-claimed-after-select', RunStatus::Queued, ageSeconds: 700);
        $selected = $this->runRepository->findAbandoned($now->modify('-10 minutes'), $now);
        self::assertCount(1, $selected);

        self::assertTrue($this->runRepository->markRunning('run-claimed-after-select', $now));

        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: new ReaperCapturingBroadcaster(),
            now: static fn(): \DateTimeImmutable => $now,
        );
        self::assertSame(0, $reaper->reapSelected($selected, 600, $now));
        self::assertSame(RunStatus::Running, $this->runRepository->find('run-claimed-after-select')?->getStatus());
    }

    #[Test]
    public function selectedExpiredApprovalCannotReapARenewedApprovalCycle(): void
    {
        $now = new \DateTimeImmutable('now');
        $expired = $now->modify('-1 minute');
        $renewed = $now->modify('+5 minutes');
        $this->seedRun(
            'run-renewed-approval',
            RunStatus::AwaitingApproval,
            ageSeconds: 700,
            approvalExpiresAt: $expired,
            pendingCallId: 'call-old',
        );
        $selected = $this->runRepository->findAbandoned($now->modify('-10 minutes'), $now);
        self::assertCount(1, $selected);

        $this->database->update('agent_run')
            ->fields([
                'pending_approval_call_id' => 'call-new',
                'approval_expires_at' => $renewed->format('Y-m-d H:i:s.uP'),
            ])
            ->condition('id', 'run-renewed-approval')
            ->execute();

        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: new ReaperCapturingBroadcaster(),
            now: static fn(): \DateTimeImmutable => $now,
        );
        self::assertSame(0, $reaper->reapSelected($selected, 600, $now));
        $run = $this->runRepository->find('run-renewed-approval');
        self::assertSame(RunStatus::AwaitingApproval, $run?->getStatus());
        self::assertSame('call-new', $run?->get('pending_approval_call_id'));
        self::assertSame($renewed->format('Y-m-d H:i:s.uP'), $run?->get('approval_expires_at'));
        self::assertSame([], $this->auditRepository->findByRunId('run-renewed-approval'));
    }

    #[Test]
    public function approvalExpiryUsesItsOwnDeadlineInsteadOfRunStart(): void
    {
        $now = new \DateTimeImmutable('2026-07-14T20:00:00+00:00');
        $this->seedRun(
            'run-long-before-approval',
            RunStatus::AwaitingApproval,
            ageSeconds: 7200,
            approvalExpiresAt: $now->modify('+5 minutes'),
        );
        $this->seedRun(
            'run-expired-approval',
            RunStatus::AwaitingApproval,
            ageSeconds: 60,
            approvalExpiresAt: $now->modify('-1 second'),
        );

        $reaper = new StalledRunReaper(
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            broadcaster: new ReaperCapturingBroadcaster(),
            now: static fn(): \DateTimeImmutable => $now,
        );

        self::assertSame(1, $reaper->reap(maxRuntimeSeconds: 600));
        self::assertSame(
            RunStatus::AwaitingApproval,
            $this->runRepository->find('run-long-before-approval')?->getStatus(),
        );
        self::assertSame(RunStatus::Failed, $this->runRepository->find('run-expired-approval')?->getStatus());
    }

    private function seedRunningRun(string $id, int $startedSecondsAgo): void
    {
        $this->seedRun($id, RunStatus::Running, $startedSecondsAgo);
    }

    private function seedRun(
        string $id,
        RunStatus $status,
        int $ageSeconds,
        ?\DateTimeImmutable $approvalExpiresAt = null,
        ?string $pendingCallId = null,
    ): void {
        $startedAt = new \DateTimeImmutable('now')->sub(new \DateInterval('PT' . $ageSeconds . 'S'));
        $run = new AgentRun([
            'id' => $id,
            'account_id' => 1,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => $status->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => $pendingCallId
                ?? ($status === RunStatus::AwaitingApproval ? 'call-awaiting' : null),
            'approval_expires_at' => $approvalExpiresAt?->format('Y-m-d H:i:s.uP'),
            'prompt' => 'stalled',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => $startedAt->format('Y-m-d H:i:s.uP'),
            'started_at' => $status === RunStatus::Queued ? null : $startedAt->format('Y-m-d H:i:s.uP'),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);
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
        $entityRepo = new EntityRepository($entityType, $driver, new EventDispatcher(), null, $this->database);

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
        $entityRepo = new EntityRepository($entityType, $driver, new EventDispatcher(), null, $this->database);

        return new AgentAuditLogRepository($entityRepo, $this->database);
    }
}

final class ReaperCapturingBroadcaster implements AgentRunBroadcasterInterface
{
    /** @var array<string, list<string>> */
    private array $events = [];

    public function push(string $runId, string $event, array $data): void
    {
        $this->events[$runId][] = $event;
    }

    /** @return list<string> */
    public function eventsFor(string $runId): array
    {
        return $this->events[$runId] ?? [];
    }
}
