<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Job;

/**
 * A job that "crashes the worker" — modelled as a worker that dies after
 * claiming but before processing. Its {@see handle()} simply records that it ran,
 * so a test can prove the worker NEVER processed it once its lease-reclaim budget
 * was exhausted (the crash-recovery safety net records it failed instead).
 */
final class CrashingJob extends Job
{
    public int $tries = 3;
    public int $retryAfter = 0;

    public static int $handleCount = 0;
    public static bool $failedCalled = false;

    public function handle(): void
    {
        self::$handleCount++;
    }

    public function failed(\Throwable $e): void
    {
        self::$failedCalled = true;
    }

    public static function reset(): void
    {
        self::$handleCount = 0;
        self::$failedCalled = false;
    }
}
