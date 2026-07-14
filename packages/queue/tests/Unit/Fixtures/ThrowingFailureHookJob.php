<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Job;

final class ThrowingFailureHookJob extends Job
{
    public function handle(): void
    {
        throw new \RuntimeException('job failed');
    }

    public function failed(\Throwable $e): void
    {
        throw new \LogicException('failure hook failed');
    }
}
