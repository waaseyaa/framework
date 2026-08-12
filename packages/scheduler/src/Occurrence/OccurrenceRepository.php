<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\ScheduledTask;

/** Durable deterministic occurrence and execution-ownership ledger. */
final class OccurrenceRepository implements OccurrenceRepositoryInterface
{
    private const string TABLE = 'waaseyaa_scheduler_occurrences';

    public function __construct(private readonly DBALDatabase $database) {}

    public function recordScheduled(ScheduledTask $task, \DateTimeInterface $due): ScheduledOccurrence
    {
        $dueAtMs = intdiv($due->getTimestamp(), 60) * 60_000;
        $generation = $task->scheduleGeneration();
        $triggerKey = 'scheduled:' . $dueAtMs;

        return $this->record($task, $generation, $dueAtMs, $triggerKey);
    }

    public function recordManual(ScheduledTask $task, \DateTimeInterface $requestedAt, string $idempotencyKey): ScheduledOccurrence
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidIdempotencyKeyException('Manual scheduler runs require an idempotency key of 1 to 255 bytes.');
        }
        $generation = $task->scheduleGeneration();
        $triggerKey = 'manual:' . hash('sha256', $idempotencyKey);

        return $this->record($task, $generation, $requestedAt->getTimestamp() * 1000, $triggerKey);
    }

    private function record(ScheduledTask $task, string $generation, int $dueAtMs, string $triggerKey): ScheduledOccurrence
    {
        $id = hash('sha256', "occurrence\0{$task->name}\0{$generation}\0{$triggerKey}");
        try {
            $this->database->insert(self::TABLE)->values([
                'occurrence_id' => $id,
                'task_name' => $task->name,
                'schedule_generation' => $generation,
                'due_at_ms' => $dueAtMs,
                'trigger_key' => $triggerKey,
                'status' => 'recorded',
                'execution_fence' => 0,
                'failure_class' => null,
            ])->execute();
        } catch (UniqueConstraintViolationException) {
            // A peer already recorded this exact cron slot.
        }

        return $this->require($id);
    }

    public function begin(string $occurrenceId, int $fence): bool
    {
        if ($fence < 1) {
            throw new \InvalidArgumentException('Occurrence execution requires a positive fence.');
        }
        return $this->database->update(self::TABLE)->fields([
            'status' => 'running',
            'execution_fence' => $fence,
            'failure_class' => null,
        ])->condition('occurrence_id', $occurrenceId)
            ->condition('status', ['recorded', 'failed', 'running'], 'IN')
            ->condition('execution_fence', $fence, '<')
            ->execute() === 1;
    }

    public function complete(string $occurrenceId, int $fence): void
    {
        $affected = $this->database->update(self::TABLE)->fields(['status' => 'completed'])
            ->condition('occurrence_id', $occurrenceId)
            ->condition('status', 'running')
            ->condition('execution_fence', $fence)
            ->execute();
        if ($affected !== 1) {
            throw new \RuntimeException('Occurrence completion lost its execution fence.');
        }
    }

    public function deadLetter(string $occurrenceId, int $fence, string $failureClass): void
    {
        $affected = $this->database->update(self::TABLE)->fields([
            'status' => 'dead_letter',
            'failure_class' => $failureClass,
        ])->condition('occurrence_id', $occurrenceId)
            ->condition('status', 'running')
            ->condition('execution_fence', $fence)
            ->execute();
        if ($affected !== 1) {
            throw new \RuntimeException('Occurrence dead-lettering lost its execution fence.');
        }
    }

    /** @param class-string<\Throwable> $failureClass */
    public function fail(string $occurrenceId, int $fence, string $failureClass): void
    {
        $this->database->update(self::TABLE)->fields([
            'status' => 'failed',
            'failure_class' => $failureClass,
        ])->condition('occurrence_id', $occurrenceId)
            ->condition('status', 'running')
            ->condition('execution_fence', $fence)
            ->execute();
    }

    public function require(string $id): ScheduledOccurrence
    {
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT occurrence_id, task_name, schedule_generation, due_at_ms, status, execution_fence FROM ' . self::TABLE . ' WHERE occurrence_id = ?',
            [$id],
        );
        if ($row === false) {
            throw new \RuntimeException("Scheduled occurrence '{$id}' is missing.");
        }

        return new ScheduledOccurrence(
            (string) $row['occurrence_id'],
            (string) $row['task_name'],
            (string) $row['schedule_generation'],
            (int) $row['due_at_ms'],
            (string) $row['status'],
            (int) $row['execution_fence'],
        );
    }
}
