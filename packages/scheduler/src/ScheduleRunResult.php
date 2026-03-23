<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

final readonly class ScheduleRunResult
{
    /**
     * @param list<string> $taskNames
     */
    public function __construct(
        public int $count,
        public array $taskNames,
    ) {}
}
