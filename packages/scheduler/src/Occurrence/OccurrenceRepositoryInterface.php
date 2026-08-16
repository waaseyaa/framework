<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Waaseyaa\Scheduler\ScheduledTask;

/** @internal */
interface OccurrenceRepositoryInterface
{
    public function recordScheduled(ScheduledTask $task, \DateTimeInterface $due): ScheduledOccurrence;

    public function recordManual(ScheduledTask $task, \DateTimeInterface $requestedAt, string $idempotencyKey): ScheduledOccurrence;

    public function begin(string $occurrenceId, int $fence): bool;

    public function require(string $id): ScheduledOccurrence;

    public function complete(string $occurrenceId, int $fence): void;

    public function deadLetter(string $occurrenceId, int $fence, string $failureClass): void;

    /** @param class-string<\Throwable> $failureClass */
    public function fail(string $occurrenceId, int $fence, string $failureClass): void;
}
