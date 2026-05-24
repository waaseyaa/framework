<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
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

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock());
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

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock());
        $result = $runner->run(new \DateTimeImmutable('2026-06-15 14:30:00'));

        self::assertSame(0, $result->count);
    }

    #[Test]
    public function preventsOverlappingTasks(): void
    {
        $lock = new InMemoryLock();
        // Pre-acquire the lock
        $lock->acquire('overlap-task', 300);

        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'overlap-task',
            expression: '* * * * *',
            command: fn() => throw new \RuntimeException('Should not run'),
            preventOverlap: true,
        ));

        $runner = new ScheduleRunner($schedule, new SyncQueue(), $lock);
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(0, $result->count);
    }

    #[Test]
    public function dispatchesJobClassToQueue(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'queue-task',
            expression: '* * * * *',
            command: \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::class,
        ));

        \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::reset();

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock());
        $result = $runner->run(new \DateTimeImmutable());

        self::assertSame(1, $result->count);
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

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock());
        $result = $runner->run(new \DateTimeImmutable());

        // Second task should still run despite first failing
        self::assertSame(1, $result->count);
        self::assertSame(['second-task'], $result->taskNames);
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
            command: function () use (&$executed) {
                $executed = true;
            },
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock(), $stateRepo);

        $result = $runner->runOne('manual-task', new \DateTimeImmutable('2026-06-15 14:30:00'));

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
    public function runOneExecutesStringCommandTaskAndDispatchesToQueue(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'queue-manual',
            expression: '0 0 1 1 *',
            command: \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::class,
        ));
        \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::reset();

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock(), $stateRepo);

        $result = $runner->runOne('queue-manual', new \DateTimeImmutable());

        self::assertSame(1, $result->count);
        self::assertSame(ScheduleRunResult::STATUS_SUCCESS, $result->status);
        self::assertNotNull($stateRepo->getState('queue-manual'));
    }

    #[Test]
    public function runOneThrowsInvalidArgumentExceptionWhenTaskNotRegistered(): void
    {
        $schedule = new Schedule();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ghost/');

        $runner->runOne('ghost', new \DateTimeImmutable());
    }

    #[Test]
    public function runOneReportsFailureWithExceptionClassWithoutSerializingThrowable(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'kaboom',
            expression: '* * * * *',
            command: fn() => throw new \DomainException('boom'),
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock(), $stateRepo);

        $result = $runner->runOne('kaboom', new \DateTimeImmutable());

        self::assertSame(0, $result->count);
        self::assertSame(ScheduleRunResult::STATUS_FAILED, $result->status);
        self::assertSame('boom', $result->message);
        self::assertSame(\DomainException::class, $result->exceptionClass);

        $state = $stateRepo->getState('kaboom');
        self::assertNotNull($state);
        self::assertStringStartsWith('failed:', $state['last_result']);
    }

    #[Test]
    public function runOneRecordsOverlapSkipAndDoesNotInvokeCommand(): void
    {
        $lock = new InMemoryLock();
        $lock->acquire('locked-task', 300);

        $invoked = false;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'locked-task',
            expression: '* * * * *',
            command: function () use (&$invoked) {
                $invoked = true;
            },
            preventOverlap: true,
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), $lock, $stateRepo);

        $result = $runner->runOne('locked-task', new \DateTimeImmutable());

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
}
