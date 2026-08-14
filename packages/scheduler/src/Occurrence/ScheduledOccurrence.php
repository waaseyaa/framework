<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

final readonly class ScheduledOccurrence
{
    public function __construct(
        public string $id,
        public string $taskName,
        public string $scheduleGeneration,
        public int $dueAtMs,
        public string $status,
        public int $executionFence,
    ) {}
}
