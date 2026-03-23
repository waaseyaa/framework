<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleRunResult;
use Waaseyaa\Scheduler\ScheduleRunner;

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
}
