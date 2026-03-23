<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Attribute\OnQueue;
use Waaseyaa\Queue\Job;

#[OnQueue('high')]
final class HighPriorityJob extends Job
{
    public function handle(): void {}
}
