<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

interface JobNextHandlerInterface
{
    public function handle(Job $job): void;
}
