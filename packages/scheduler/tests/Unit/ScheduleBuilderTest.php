<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduleBuilder;
use Waaseyaa\Scheduler\Execution\LeaseAwareClosureCommand;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Queue\Tests\Unit\Fixtures\OccurrenceAwareJob;

#[CoversClass(ScheduleBuilder::class)]
#[CoversClass(Schedule::class)]
final class ScheduleBuilderTest extends TestCase
{
    #[Test]
    public function buildsTaskWithFluentApi(): void
    {
        $schedule = new Schedule();
        $task = $schedule->command(new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context): void {}))
            ->everyFiveMinutes()
            ->named('cleanup')
            ->withoutOverlapping()
            ->describedAs('Clean up temp files')
            ->register();

        self::assertSame('cleanup', $task->name);
        self::assertSame('*/5 * * * *', $task->expression);
        self::assertTrue($task->preventOverlap);
        self::assertSame('Clean up temp files', $task->description);
        self::assertCount(1, $schedule->tasks());
    }

    #[Test]
    public function buildsCallableTask(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(fn() => null)
            ->daily()
            ->named('daily-task')
            ->register();

        self::assertSame('0 0 * * *', $task->expression);
        self::assertInstanceOf(\Closure::class, $task->command);
    }

    #[Test]
    public function dailyAtSetsCronExpression(): void
    {
        $schedule = new Schedule();
        $task = $schedule->job(OccurrenceAwareJob::class)
            ->dailyAt('03:30')
            ->named('early-morning')
            ->withoutOverlapping()
            ->register();

        self::assertSame('30 03 * * *', $task->expression);
    }

    #[Test]
    public function queuedJobUsesExplicitStableName(): void
    {
        $schedule = new Schedule();
        $task = $schedule->job(OccurrenceAwareJob::class)
            ->weekly()
            ->named('weekly-report')
            ->withoutOverlapping()
            ->register();

        self::assertSame('weekly-report', $task->name);
    }

    #[Test]
    public function supportsCronHelpers(): void
    {
        $schedule = new Schedule();

        $schedule->job(OccurrenceAwareJob::class)->everyMinute()->named('a')->withoutOverlapping()->register();
        $schedule->job(OccurrenceAwareJob::class)->everyTenMinutes()->named('b')->withoutOverlapping()->register();
        $schedule->job(OccurrenceAwareJob::class)->everyFifteenMinutes()->named('c')->withoutOverlapping()->register();
        $schedule->job(OccurrenceAwareJob::class)->everyThirtyMinutes()->named('d')->withoutOverlapping()->register();
        $schedule->job(OccurrenceAwareJob::class)->hourly()->named('e')->withoutOverlapping()->register();
        $schedule->job(OccurrenceAwareJob::class)->monthly()->named('f')->withoutOverlapping()->register();

        $tasks = $schedule->tasks();
        self::assertSame('* * * * *', $tasks[0]->expression);
        self::assertSame('*/10 * * * *', $tasks[1]->expression);
        self::assertSame('*/15 * * * *', $tasks[2]->expression);
        self::assertSame('*/30 * * * *', $tasks[3]->expression);
        self::assertSame('0 * * * *', $tasks[4]->expression);
        self::assertSame('0 0 1 * *', $tasks[5]->expression);
    }

    #[Test]
    public function overlapProtectedCommandRequiresAnExplicitStableName(): void
    {
        $schedule = new Schedule();
        $builder = $schedule->command(new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context): void {}))
            ->withoutOverlapping();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit stable name');
        $builder->register();
    }
}
