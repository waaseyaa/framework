<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Occurrence;

use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;

final class NoOccurrenceRuntime implements OccurrenceRuntimeInterface
{
    public function run(QueueOccurrenceV1 $occurrence, callable $execute): OccurrenceRunResult
    {
        throw new \RuntimeException('Queued scheduler occurrence execution requires a durable scheduler runtime.');
    }

    public function deadLetter(QueueOccurrenceV1 $occurrence, string $failureClass): bool
    {
        throw new \RuntimeException('Queued scheduler occurrence dead-lettering requires a durable scheduler runtime.');
    }
}
