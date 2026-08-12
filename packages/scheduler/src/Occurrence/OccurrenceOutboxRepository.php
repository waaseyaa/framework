<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Scheduler\ScheduledTask;

/** Scheduler-owned transactional occurrence/enqueue-outbox authority. */
final readonly class OccurrenceOutboxRepository
{
    private const string TABLE = 'waaseyaa_scheduler_occurrence_outbox';

    public function __construct(
        private DBALDatabase $database,
        private OccurrenceRepositoryInterface $occurrences,
    ) {}

    public function recordScheduled(ScheduledTask $task, \DateTimeInterface $due): ScheduledOccurrence
    {
        return $this->record($task, fn(): ScheduledOccurrence => $this->occurrences->recordScheduled($task, $due));
    }

    public function recordManual(ScheduledTask $task, \DateTimeInterface $requestedAt, string $idempotencyKey): ScheduledOccurrence
    {
        return $this->record(
            $task,
            fn(): ScheduledOccurrence => $this->occurrences->recordManual($task, $requestedAt, $idempotencyKey),
        );
    }

    /** @param callable(): ScheduledOccurrence $recordOccurrence */
    private function record(ScheduledTask $task, callable $recordOccurrence): ScheduledOccurrence
    {
        if (!is_string($task->command) || !is_a($task->command, OccurrenceAwareMessageInterface::class, true)) {
            throw new \InvalidArgumentException('Queued scheduler tasks require an occurrence-aware message class.');
        }

        return $this->database->getConnection()->transactional(function () use ($task, $recordOccurrence): ScheduledOccurrence {
            $occurrence = $recordOccurrence();
            try {
                $this->database->insert(self::TABLE)->values([
                    'occurrence_id' => $occurrence->id,
                    'message_class' => $task->command,
                    'lease_ttl_ms' => $task->lockTtl * 1000,
                    'state' => 'pending',
                    'attempts' => 0,
                    'last_error_class' => null,
                ])->execute();
            } catch (UniqueConstraintViolationException) {
                // Ambiguous producer retry: the exact occurrence already owns one outbox row.
            }

            return $occurrence;
        });
    }

    /** @return list<OccurrenceOutboxEntry> */
    public function pending(int $limit = 100, ?string $occurrenceId = null): array
    {
        $where = "WHERE x.state = 'pending'";
        $parameters = [];
        $types = [];
        if ($occurrenceId !== null) {
            $where .= ' AND x.occurrence_id = ?';
            $parameters[] = $occurrenceId;
            $types[] = \Doctrine\DBAL\ParameterType::STRING;
        }
        $parameters[] = max(1, $limit);
        $types[] = \Doctrine\DBAL\ParameterType::INTEGER;
        $rows = $this->database->getConnection()->fetchAllAssociative(
            'SELECT o.occurrence_id, o.task_name, o.schedule_generation, x.message_class, x.lease_ttl_ms '
            . 'FROM ' . self::TABLE . ' x INNER JOIN waaseyaa_scheduler_occurrences o ON o.occurrence_id = x.occurrence_id '
            . $where . ' ORDER BY o.due_at_ms ASC, o.occurrence_id ASC LIMIT ?',
            $parameters,
            $types,
        );

        return array_map(
            static fn(array $row): OccurrenceOutboxEntry => new OccurrenceOutboxEntry(
                (string) $row['occurrence_id'],
                (string) $row['task_name'],
                (string) $row['schedule_generation'],
                (string) $row['message_class'],
                (int) $row['lease_ttl_ms'],
            ),
            $rows,
        );
    }

    public function markDispatched(string $occurrenceId): void
    {
        $this->database->update(self::TABLE)->fields([
            'state' => 'dispatched',
            'last_error_class' => null,
        ])->condition('occurrence_id', $occurrenceId)
            ->condition('state', 'pending')
            ->execute();
    }

    public function state(string $occurrenceId): ?string
    {
        $state = $this->database->getConnection()->fetchOne(
            'SELECT state FROM ' . self::TABLE . ' WHERE occurrence_id = ?',
            [$occurrenceId],
        );

        return $state === false ? null : (string) $state;
    }

    /** @param class-string<\Throwable> $errorClass */
    public function markFailed(string $occurrenceId, string $errorClass, int $maxAttempts): void
    {
        $this->database->getConnection()->transactional(function () use ($occurrenceId, $errorClass, $maxAttempts): void {
            $attempts = $this->database->getConnection()->fetchOne(
                'SELECT attempts FROM ' . self::TABLE . " WHERE occurrence_id = ? AND state = 'pending'",
                [$occurrenceId],
            );
            if ($attempts === false) {
                return;
            }
            $nextAttempts = (int) $attempts + 1;
            $state = $nextAttempts >= $maxAttempts ? 'dead_letter' : 'pending';
            $this->database->update(self::TABLE)->fields([
                'attempts' => $nextAttempts,
                'last_error_class' => $errorClass,
                'state' => $state,
            ])->condition('occurrence_id', $occurrenceId)
                ->condition('state', 'pending')
                ->condition('attempts', (int) $attempts)
                ->execute();
            if ($state === 'dead_letter') {
                $this->database->update('waaseyaa_scheduler_occurrences')->fields([
                    'status' => 'dead_letter',
                    'failure_class' => $errorClass,
                ])->condition('occurrence_id', $occurrenceId)
                    ->condition('status', 'recorded')
                    ->condition('execution_fence', 0)
                    ->execute();
            }
        });
    }
}
