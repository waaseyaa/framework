<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\ScheduledTask;

#[CoversClass(ScheduledTask::class)]
final class ScheduledTaskTest extends TestCase
{
    #[Test]
    public function isDueReturnsTrueWhenExpressionMatches(): void
    {
        // Every minute
        $task = new ScheduledTask(
            name: 'test',
            expression: '* * * * *',
            command: fn() => null,
        );

        self::assertTrue($task->isDue(new \DateTimeImmutable()));
    }

    #[Test]
    public function isDueReturnsFalseWhenExpressionDoesNotMatch(): void
    {
        // Only at midnight on January 1st
        $task = new ScheduledTask(
            name: 'test',
            expression: '0 0 1 1 *',
            command: fn() => null,
        );

        // Use a date that's clearly not Jan 1 midnight
        $now = new \DateTimeImmutable('2026-06-15 14:30:00');
        self::assertFalse($task->isDue($now));
    }

    #[Test]
    public function isDueRespectsTimezone(): void
    {
        // At 10:00 UTC
        $task = new ScheduledTask(
            name: 'test',
            expression: '0 10 * * *',
            command: fn() => null,
            timezone: 'UTC',
        );

        $utc10am = new \DateTimeImmutable('2026-03-23 10:00:00', new \DateTimeZone('UTC'));
        self::assertTrue($task->isDue($utc10am));

        $utc11am = new \DateTimeImmutable('2026-03-23 11:00:00', new \DateTimeZone('UTC'));
        self::assertFalse($task->isDue($utc11am));
    }

    #[Test]
    public function getNextRunDateReturnsCorrectDate(): void
    {
        $task = new ScheduledTask(
            name: 'test',
            expression: '0 * * * *', // Every hour
            command: fn() => null,
        );

        $now = new \DateTimeImmutable('2026-03-23 14:30:00');
        $next = $task->getNextRunDate($now);

        self::assertSame('2026-03-23 15:00:00', $next->format('Y-m-d H:i:s'));
    }
}
