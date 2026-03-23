<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduleBuilder;

#[CoversClass(ScheduleBuilder::class)]
#[CoversClass(Schedule::class)]
final class ScheduleBuilderTest extends TestCase
{
    #[Test]
    public function buildsTaskWithFluentApi(): void
    {
        $schedule = new Schedule();
        $task = $schedule->job('App\\Jobs\\CleanUp')
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
        $task = $schedule->job('SomeJob')
            ->dailyAt('03:30')
            ->named('early-morning')
            ->register();

        self::assertSame('30 03 * * *', $task->expression);
    }

    #[Test]
    public function autoGeneratesNameFromJobClass(): void
    {
        $schedule = new Schedule();
        $task = $schedule->job('App\\Jobs\\SendReport')
            ->weekly()
            ->register();

        self::assertSame('App\\Jobs\\SendReport', $task->name);
    }

    #[Test]
    public function supportsCronHelpers(): void
    {
        $schedule = new Schedule();

        $schedule->job('A')->everyMinute()->named('a')->register();
        $schedule->job('B')->everyTenMinutes()->named('b')->register();
        $schedule->job('C')->everyFifteenMinutes()->named('c')->register();
        $schedule->job('D')->everyThirtyMinutes()->named('d')->register();
        $schedule->job('E')->hourly()->named('e')->register();
        $schedule->job('F')->monthly()->named('f')->register();

        $tasks = $schedule->tasks();
        self::assertSame('* * * * *', $tasks[0]->expression);
        self::assertSame('*/10 * * * *', $tasks[1]->expression);
        self::assertSame('*/15 * * * *', $tasks[2]->expression);
        self::assertSame('*/30 * * * *', $tasks[3]->expression);
        self::assertSame('0 * * * *', $tasks[4]->expression);
        self::assertSame('0 0 1 * *', $tasks[5]->expression);
    }
}
