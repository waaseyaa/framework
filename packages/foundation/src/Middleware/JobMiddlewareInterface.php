<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

interface JobMiddlewareInterface
{
    public function process(Job $job, JobNextHandlerInterface $next): void;
}
