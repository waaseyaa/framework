<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Occurrence;

use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;

/** Scheduler-supplied worker runtime; the queue package owns no lease authority. @api */
interface OccurrenceRuntimeInterface
{
    /** @param callable(OccurrenceContextInterface): void $execute */
    public function run(QueueOccurrenceV1 $occurrence, callable $execute): OccurrenceRunResult;

    /** Acquire successor ownership and make terminal transport exhaustion durable. */
    public function deadLetter(QueueOccurrenceV1 $occurrence, string $failureClass): bool;
}
