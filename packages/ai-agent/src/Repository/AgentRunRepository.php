<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Repository;

use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * High-level repository for the `agent_run` aggregate.
 *
 * CRUD flows through the injected {@see EntityRepositoryInterface} (events,
 * hydration, language fallback). Status transitions
 * ({@see markRunning()}, {@see markTerminal()}) are compare-and-swap
 * UPDATEs issued directly through {@see DatabaseInterface} so two workers
 * cannot race past the C-014 invariant ("terminal statuses cannot regress").
 *
 * Callers MUST treat a `false` return from a compare-and-swap method as
 * authoritative: the row has already advanced and the caller must not
 * proceed with the side effects the transition would have implied.
 *
 * @api
 */
final class AgentRunRepository
{
    private const TABLE = 'agent_run';

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * Find a run by id.
     */
    public function find(string $id): ?AgentRun
    {
        $entity = $this->entityRepository->find($id);

        return $entity instanceof AgentRun ? $entity : null;
    }

    /**
     * Persist a run through the entity repository (events, validation, hydration).
     *
     * When the caller pre-sets the id (the typical case for queued runs),
     * `enforceIsNew(true)` MUST already be set on the entity. Callers using
     * {@see for()}-style factories get this for free; raw `new AgentRun([...])`
     * callers must invoke it explicitly before the first save.
     */
    public function save(AgentRun $run): void
    {
        $this->entityRepository->save($run);
    }

    /**
     * Compare-and-swap: transition `queued → running` exactly once.
     *
     * Returns `false` if the row is no longer in `queued` (already picked up
     * by another worker, cancelled before pickup, etc.).
     *
     * Persists `started_at` atomically with the status flip — the reaper
     * uses `(status='running', started_at < threshold)` to detect stuck runs.
     */
    public function markRunning(string $id, \DateTimeImmutable $startedAt): bool
    {
        $affected = $this->database->update(self::TABLE)
            ->fields([
                'status' => RunStatus::Running->value,
                'started_at' => $this->formatDateTime($startedAt),
            ])
            ->condition('id', $id)
            ->condition('status', RunStatus::Queued->value)
            ->execute();

        return $affected === 1;
    }

    /**
     * Compare-and-swap: transition into a terminal status (`completed`,
     * `failed`, or `cancelled`).
     *
     * Refuses to overwrite an existing terminal row — the affected-rows
     * guard implements the C-014 invariant directly in SQL: the WHERE
     * clause excludes the three terminal statuses, so a second worker
     * issuing the same transition gets `affected === 0` and a `false`
     * return.
     *
     * @throws \InvalidArgumentException When `$status` is not terminal.
     */
    public function markTerminal(
        string $id,
        RunStatus $status,
        \DateTimeImmutable $finishedAt,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): bool {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException(\sprintf(
                'markTerminal() requires a terminal status; got "%s".',
                $status->value,
            ));
        }

        $fields = [
            'status' => $status->value,
            'finished_at' => $this->formatDateTime($finishedAt),
        ];
        if ($errorCode !== null) {
            $fields['error_code'] = $errorCode;
        }
        if ($errorMessage !== null) {
            $fields['error_message'] = $errorMessage;
        }

        $update = $this->database->update(self::TABLE)
            ->fields($fields)
            ->condition('id', $id);

        // C-014: refuse to advance over any terminal status. We use one
        // operator per condition because the query builder rejects array
        // values on plain '=' equality.
        foreach (RunStatus::terminals() as $terminal) {
            $update = $update->condition('status', $terminal->value, '!=');
        }

        $affected = $update->execute();

        return $affected === 1;
    }

    /**
     * Find runs whose `status='running'` and `started_at < $threshold`.
     *
     * Used by the reaper to detect worker-crash victims. Backed by
     * `idx_agent_run_status_started_at`.
     *
     * @return list<AgentRun>
     */
    public function findStuckRunning(\DateTimeImmutable $threshold): array
    {
        $thresholdString = $this->formatDateTime($threshold);

        $rows = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, ['id'])
            ->condition('status', RunStatus::Running->value)
            ->condition('started_at', $thresholdString, '<')
            ->execute();

        $results = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $entity = $this->find($id);
            if ($entity !== null) {
                $results[] = $entity;
            }
        }

        return $results;
    }

    /**
     * Find TERMINAL runs (`completed`, `failed`, `cancelled`) queued before
     * `$threshold`.
     *
     * Used by the audit-retention purge job. Non-terminal runs (`queued`,
     * `running`, `awaiting_approval`, `cancelling`) are excluded regardless
     * of age: a run stuck waiting for a worker or human approval is still
     * logically live, and the reaper (not this method) is responsible for
     * detecting a stalled `running` row. Age alone must never be a reason
     * to delete a run the reaper has not yet classified as dead.
     *
     * @return list<AgentRun>
     */
    public function findOldByQueuedAt(\DateTimeImmutable $threshold): array
    {
        $thresholdString = $this->formatDateTime($threshold);

        $terminalValues = array_map(
            static fn(RunStatus $status): string => $status->value,
            RunStatus::terminals(),
        );

        $rows = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, ['id'])
            ->condition('queued_at', $thresholdString, '<')
            ->condition('status', $terminalValues, 'IN')
            ->execute();

        $results = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $entity = $this->find($id);
            if ($entity !== null) {
                $results[] = $entity;
            }
        }

        return $results;
    }

    /**
     * Storage-canonical ISO-8601 timestamp string for `DATETIMETZ` columns.
     *
     * Includes microseconds + offset so MySQL/Postgres preserve precision
     * and SQLite (TEXT-typed) sorts correctly lexicographically.
     */
    private function formatDateTime(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s.uP');
    }
}
