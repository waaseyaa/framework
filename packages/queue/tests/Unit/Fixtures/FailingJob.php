<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Job;

final class FailingJob extends Job
{
    public int $tries = 3;
    public int $retryAfter = 0;
    public static bool $failedCalled = false;

    public function handle(): void
    {
        throw new \RuntimeException('Job failed');
    }

    public function failed(\Throwable $e): void
    {
        self::$failedCalled = true;
    }

    public static function reset(): void
    {
        self::$failedCalled = false;
    }
}
