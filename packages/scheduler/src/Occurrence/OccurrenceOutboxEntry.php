<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;

final readonly class OccurrenceOutboxEntry
{
    /** @param class-string $messageClass */
    public function __construct(
        public string $occurrenceId,
        public string $taskName,
        public string $scheduleGeneration,
        public string $messageClass,
        public int $leaseTtlMs,
    ) {}

    public function queueOccurrence(): QueueOccurrenceV1
    {
        return new QueueOccurrenceV1(
            $this->occurrenceId,
            $this->taskName,
            $this->scheduleGeneration,
            $this->leaseTtlMs,
        );
    }
}
