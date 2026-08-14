<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Queue\OccurrenceQueueInterface;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Scheduler\Execution\LeaseAwareClosureCommand;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Occurrence\UnsafeManualExecutionException;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxDispatcher;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepository;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;
use Waaseyaa\Scheduler\Testing\InMemoryOccurrenceRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\OccurrenceAwareJob;
use Waaseyaa\Scheduler\Lease\UnavailableLeaseAuthority;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleRunResult;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

#[CoversClass(ScheduleRunner::class)]
#[CoversClass(ScheduleRunResult::class)]
final class ScheduleRunnerTest extends TestCase
{
    #[Test]
    public function runsDueTasks(): void
    {
        $executed = false;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'test-task',
            expression: '* * * * *',
            command: function () use (&$executed) {
                $executed = true;
            },
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());
        $result = $runner->run(new \DateTimeImmutable());

        self::assertTrue($executed);
        self::assertSame(1, $result->count);
        self::assertSame(['test-task'], $result->taskNames);
    }

    #[Test]
    public function skipsTasksNotDue(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'yearly',
            expression: '0 0 1 1 *', // Jan 1 midnight only
            command: fn() => null,
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());
        $result = $runner->run(new \DateTimeImmutable('2026-06-15 14:30:00'));

        self::assertSame(0, $result->count);
    }

    #[Test]
    public function preventsOverlappingTasks(): void
    {
        $lock = new InMemoryLeaseAuthority();
        // Pre-acquire the lock
        $lock->acquire('overlap-task', 300_000);

        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'overlap-task',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static fn(LeaseExecutionContext $context) => throw new \RuntimeException('Should not run')),
            preventOverlap: true,
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), $lock, occurrenceRepository: new InMemoryOccurrenceRepository());
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(0, $result->count);
        // An overlap-locked task is an intentional skip, not a failure:
        // lock contention must never make schedule:run exit nonzero.
        self::assertSame(0, $result->failedCount);
        self::assertSame([], $result->failedTaskNames);
    }

    #[Test]
    public function unavailableDurableAuthorityIsFailureNotOverlap(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'requires-durable-lease',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static fn(LeaseExecutionContext $context) => null),
            preventOverlap: true,
        ));

        $result = (new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new UnavailableLeaseAuthority(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        ))
            ->run(new \DateTimeImmutable());

        self::assertNull($result->status);
        self::assertSame(1, $result->failedCount);
        self::assertSame(['requires-durable-lease'], $result->failedTaskNames);
    }

    #[Test]
    public function leaseAwareTaskCarriesItsFenceIntoTheDurableEffect(): void
    {
        $observedFence = null;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'fenced-task',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use (&$observedFence): void {
                $context->effect('resource:1', 'effect:1', static function () use ($context, &$observedFence): void {
                    $observedFence = $context->fence();
                });
            }),
            preventOverlap: true,
        ));

        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLeaseAuthority(),
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(1, $result->count);
        self::assertSame(1, $observedFence);
    }

    #[Test]
    public function sameScheduledOccurrenceExecutesOnlyOnce(): void
    {
        $runs = 0;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'once-per-slot',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use (&$runs): void {
                $context->effect('resource', 'write', static function () use (&$runs): void {
                    ++$runs;
                });
            }),
            preventOverlap: true,
        ));
        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLeaseAuthority(),
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );
        $now = new \DateTimeImmutable('2026-08-12 10:14:20 UTC');

        self::assertSame(1, $runner->run($now)->count);
        self::assertSame(0, $runner->run($now)->count);
        self::assertSame(1, $runs);
    }

    #[Test]
    public function dispatchesJobClassToQueue(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'queue-task',
            expression: '* * * * *',
            command: OccurrenceAwareJob::class,
            preventOverlap: true,
        ));
        [$runner, $queue] = self::queuedRunner($schedule);
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(1, $result->count);
        self::assertSame(ScheduleRunResult::STATUS_ENQUEUED, $queue->lastStatus);
        self::assertSame(1, $queue->dispatches);
    }

    #[Test]
    public function handlesTaskFailureGracefully(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'failing-task',
            expression: '* * * * *',
            command: fn() => throw new \RuntimeException('Boom'),
        ));
        $schedule->add(new ScheduledTask(
            name: 'second-task',
            expression: '* * * * *',
            command: fn() => null,
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());
        $result = $runner->run(new \DateTimeImmutable());

        // Second task should still run despite first failing
        self::assertSame(1, $result->count);
        self::assertSame(['second-task'], $result->taskNames);
        self::assertSame(1, $result->failedCount);
        self::assertSame(['failing-task'], $result->failedTaskNames);
    }

    #[Test]
    public function reportsZeroFailedCountWhenAllDueTasksSucceed(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'test-task',
            expression: '* * * * *',
            command: fn() => null,
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(0, $result->failedCount);
        self::assertSame([], $result->failedTaskNames);
    }

    #[Test]
    public function reportsAllTasksFailedWhenEveryDueTaskThrows(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'first-failure',
            expression: '* * * * *',
            command: fn() => throw new \RuntimeException('one'),
        ));
        $schedule->add(new ScheduledTask(
            name: 'second-failure',
            expression: '* * * * *',
            command: fn() => throw new \RuntimeException('two'),
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(0, $result->count);
        self::assertSame(2, $result->failedCount);
        self::assertSame(['first-failure', 'second-failure'], $result->failedTaskNames);
    }

    // --- M4B WP02 — runOne() coverage -------------------------------------

    #[Test]
    public function runOneExecutesClosureTaskAndReportsSuccess(): void
    {
        $executed = false;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'manual-task',
            expression: '0 0 1 1 *', // Never due — runOne() bypasses isDue().
            command: new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use (&$executed): void {
                $context->effect('manual-task', 'execute', static function () use (&$executed): void {
                    $executed = true;
                });
            }),
            preventOverlap: true,
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLeaseAuthority(),
            $stateRepo,
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );

        $result = $runner->runOne('manual-task', new \DateTimeImmutable('2026-06-15 14:30:00'), 'manual-1');

        self::assertTrue($executed, 'closure command must run even when isDue() would be false');
        self::assertSame(1, $result->count);
        self::assertSame(['manual-task'], $result->taskNames);
        self::assertSame(ScheduleRunResult::STATUS_SUCCESS, $result->status);
        self::assertNotNull($result->message);
        self::assertNull($result->exceptionClass);

        $state = $stateRepo->getState('manual-task');
        self::assertNotNull($state);
        self::assertSame(ScheduleRunResult::STATUS_SUCCESS, $state['last_result']);
    }

    #[Test]
    public function runOneQueuesOneOccurrenceForOneIdempotencyKey(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'queue-manual',
            expression: '0 0 1 1 *',
            command: OccurrenceAwareJob::class,
            preventOverlap: true,
        ));
        [$runner, $queue] = self::queuedRunner($schedule);

        self::assertSame(ScheduleRunResult::STATUS_ENQUEUED, $runner->runOne('queue-manual', new \DateTimeImmutable(), 'manual-2')->status);
        self::assertSame(ScheduleRunResult::STATUS_ENQUEUED, $runner->runOne('queue-manual', new \DateTimeImmutable(), 'manual-2')->status);
        self::assertSame(1, $queue->dispatches);
    }

    #[Test]
    public function runOneThrowsInvalidArgumentExceptionWhenTaskNotRegistered(): void
    {
        $schedule = new Schedule();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ghost/');

        $runner->runOne('ghost', new \DateTimeImmutable(), 'manual-3');
    }

    #[Test]
    public function runOneRequiresCallerIdempotencyKey(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask('manual', '* * * * *', static fn() => null));
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority());

        $this->expectException(\Waaseyaa\Scheduler\Occurrence\IdempotencyKeyRequiredException::class);
        $runner->runOne('manual', new \DateTimeImmutable());
    }

    #[Test]
    public function runOneIdempotencyKeyExecutesOverlapCommandOnce(): void
    {
        $runs = 0;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'manual-once',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use (&$runs): void {
                $context->effect('manual-resource', 'manual-write', static function () use (&$runs): void {
                    ++$runs;
                });
            }),
            preventOverlap: true,
        ));
        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLeaseAuthority(),
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );

        self::assertSame(ScheduleRunResult::STATUS_SUCCESS, $runner->runOne('manual-once', new \DateTimeImmutable(), 'request-1')->status);
        self::assertSame(ScheduleRunResult::STATUS_SKIPPED_DUPLICATE, $runner->runOne('manual-once', new \DateTimeImmutable(), 'request-1')->status);
        self::assertSame(1, $runs);
    }

    #[Test]
    public function runOneReportsFailureWithExceptionClassWithoutSerializingThrowable(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'kaboom',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(
                static fn(LeaseExecutionContext $context) => throw new \DomainException('boom'),
            ),
            preventOverlap: true,
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLeaseAuthority(),
            $stateRepo,
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );

        $result = $runner->runOne('kaboom', new \DateTimeImmutable(), 'manual-4');

        self::assertSame(0, $result->count);
        self::assertSame(ScheduleRunResult::STATUS_FAILED, $result->status);
        self::assertSame('boom', $result->message);
        self::assertSame(\DomainException::class, $result->exceptionClass);
        // runOne() reports its outcome via status/exceptionClass; the sweep
        // aggregate failedCount stays 0 on runOne() results by contract.
        self::assertSame(0, $result->failedCount);

        $state = $stateRepo->getState('kaboom');
        self::assertNotNull($state);
        self::assertStringStartsWith('failed:', $state['last_result']);
    }

    #[Test]
    public function runOneRecordsOverlapSkipAndDoesNotInvokeCommand(): void
    {
        $lock = new InMemoryLeaseAuthority();
        $lock->acquire('locked-task', 300_000);

        $invoked = false;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'locked-task',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(function (LeaseExecutionContext $context) use (&$invoked) {
                $invoked = true;
            }),
            preventOverlap: true,
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            $lock,
            $stateRepo,
            occurrenceRepository: new InMemoryOccurrenceRepository(),
        );

        $result = $runner->runOne('locked-task', new \DateTimeImmutable(), 'manual-5');

        self::assertFalse($invoked);
        self::assertSame(0, $result->count);
        self::assertSame(ScheduleRunResult::STATUS_SKIPPED_OVERLAP, $result->status);
        self::assertSame(ScheduleRunResult::STATUS_SKIPPED_OVERLAP, $stateRepo->getState('locked-task')['last_result'] ?? null);
    }

    private static function makeStateRepository(): ScheduleStateRepository
    {
        $db = DBALDatabase::createSqlite();
        $db->query('
            CREATE TABLE waaseyaa_schedule_state (
                task_name VARCHAR(255) PRIMARY KEY,
                last_run_at VARCHAR(50) NOT NULL,
                last_result TEXT NOT NULL
            )
        ');

        return new ScheduleStateRepository($db);
    }

    /** @return array{ScheduleRunner, object&OccurrenceQueueInterface} */
    private static function queuedRunner(Schedule $schedule): array
    {
        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE waaseyaa_scheduler_occurrences (occurrence_id VARCHAR(64) PRIMARY KEY, task_name VARCHAR(255) NOT NULL, schedule_generation VARCHAR(64) NOT NULL, due_at_ms INTEGER NOT NULL, trigger_key VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, execution_fence INTEGER NOT NULL DEFAULT 0, failure_class VARCHAR(512) NULL, UNIQUE (task_name, schedule_generation, trigger_key))');
        $database->query('CREATE TABLE waaseyaa_scheduler_occurrence_outbox (occurrence_id VARCHAR(64) PRIMARY KEY, message_class VARCHAR(512) NOT NULL, lease_ttl_ms INTEGER NOT NULL, state VARCHAR(32) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_error_class VARCHAR(512) NULL)');
        $occurrences = new OccurrenceRepository($database);
        $outbox = new OccurrenceOutboxRepository($database, $occurrences);
        $queue = new class implements OccurrenceQueueInterface {
            public int $dispatches = 0;
            public ?string $lastStatus = null;
            public function dispatch(object $message): void {}
            public function dispatchOccurrence(object $message, QueueOccurrenceV1 $occurrence): void
            {
                ++$this->dispatches;
                $this->lastStatus = ScheduleRunResult::STATUS_ENQUEUED;
            }
        };
        $dispatcher = new OccurrenceOutboxDispatcher($outbox, $queue);
        $runner = new ScheduleRunner(
            $schedule,
            $queue,
            new InMemoryLeaseAuthority(),
            fenceGuard: new InMemoryFenceGuard(),
            occurrenceRepository: $occurrences,
            occurrenceOutbox: $outbox,
            occurrenceOutboxDispatcher: $dispatcher,
        );

        return [$runner, $queue];
    }
}
