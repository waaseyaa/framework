<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Testing;

use Waaseyaa\Scheduler\Occurrence\OccurrenceRepositoryInterface;
use Waaseyaa\Scheduler\Occurrence\ScheduledOccurrence;
use Waaseyaa\Scheduler\ScheduledTask;

/** Process-local deterministic occurrence ledger for tests only. */
final class InMemoryOccurrenceRepository implements OccurrenceRepositoryInterface
{
    /** @var array<string, ScheduledOccurrence> */
    private array $occurrences = [];

    public function recordScheduled(ScheduledTask $task, \DateTimeInterface $due): ScheduledOccurrence
    {
        $dueAtMs = intdiv($due->getTimestamp(), 60) * 60_000;
        $generation = $task->scheduleGeneration();
        $id = hash('sha256', "occurrence\0{$task->name}\0{$generation}\0scheduled:{$dueAtMs}");

        return $this->occurrences[$id] ??= new ScheduledOccurrence($id, $task->name, $generation, $dueAtMs, 'recorded', 0);
    }

    public function recordManual(ScheduledTask $task, \DateTimeInterface $requestedAt, string $idempotencyKey): ScheduledOccurrence
    {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('Manual scheduler runs require an idempotency key.');
        }
        $generation = $task->scheduleGeneration();
        $id = hash('sha256', "occurrence\0{$task->name}\0{$generation}\0manual:" . hash('sha256', $idempotencyKey));

        return $this->occurrences[$id] ??= new ScheduledOccurrence($id, $task->name, $generation, $requestedAt->getTimestamp() * 1000, 'recorded', 0);
    }

    public function begin(string $occurrenceId, int $fence): bool
    {
        $current = $this->occurrences[$occurrenceId] ?? null;
        if (
            $current === null
            || in_array($current->status, ['completed', 'dead_letter'], true)
            || $current->executionFence >= $fence
        ) {
            return false;
        }
        $this->occurrences[$occurrenceId] = new ScheduledOccurrence($current->id, $current->taskName, $current->scheduleGeneration, $current->dueAtMs, 'running', $fence);

        return true;
    }

    public function complete(string $occurrenceId, int $fence): void
    {
        $current = $this->occurrences[$occurrenceId] ?? null;
        if ($current === null || $current->status !== 'running' || $current->executionFence !== $fence) {
            throw new \RuntimeException('Occurrence completion lost its execution fence.');
        }
        $this->occurrences[$occurrenceId] = new ScheduledOccurrence($current->id, $current->taskName, $current->scheduleGeneration, $current->dueAtMs, 'completed', $fence);
    }

    public function require(string $id): ScheduledOccurrence
    {
        return $this->occurrences[$id] ?? throw new \RuntimeException("Scheduled occurrence '{$id}' is missing.");
    }

    public function deadLetter(string $occurrenceId, int $fence, string $failureClass): void
    {
        $current = $this->require($occurrenceId);
        if ($current->status !== 'running' || $current->executionFence !== $fence) {
            throw new \RuntimeException('Occurrence dead-lettering lost its execution fence.');
        }
        $this->occurrences[$occurrenceId] = new ScheduledOccurrence(
            $current->id,
            $current->taskName,
            $current->scheduleGeneration,
            $current->dueAtMs,
            'dead_letter',
            $fence,
        );
    }

    public function fail(string $occurrenceId, int $fence, string $failureClass): void
    {
        $current = $this->occurrences[$occurrenceId] ?? null;
        if ($current !== null && $current->status === 'running' && $current->executionFence === $fence) {
            $this->occurrences[$occurrenceId] = new ScheduledOccurrence($current->id, $current->taskName, $current->scheduleGeneration, $current->dueAtMs, 'failed', $fence);
        }
    }

    public function get(string $id): ?ScheduledOccurrence
    {
        return $this->occurrences[$id] ?? null;
    }
}
