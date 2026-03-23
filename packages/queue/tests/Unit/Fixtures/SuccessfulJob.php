<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Job;

final class SuccessfulJob extends Job
{
    public static int $handleCount = 0;

    public function handle(): void
    {
        self::$handleCount++;
    }

    public static function reset(): void
    {
        self::$handleCount = 0;
    }
}
