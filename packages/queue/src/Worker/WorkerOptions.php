<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Worker;

/**
 * Configuration for a queue worker process.
 */
final class WorkerOptions
{
    public function __construct(
        public readonly int $sleep = 3,
        public readonly int $maxJobs = 0,
        public readonly int $maxTime = 0,
        public readonly int $memoryLimit = 128,
        public readonly int $timeout = 60,
        public readonly int $maxTries = 3,
    ) {}
}
