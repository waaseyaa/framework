<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Repository;

use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Reaper\StalledRunCandidate;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\UpdateInterface;
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
     * Always refuses to overwrite an existing terminal row. Reaper callers
     * additionally provide the immutable candidate captured by selection, so
     * its source status and lifecycle identity participate in the same CAS.
     *
     * @throws \InvalidArgumentException When `$status` is not terminal.
     */
    public function markTerminal(
        string $id,
        RunStatus $status,
        \DateTimeImmutable $finishedAt,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?RunStatus $expectedStatus = null,
        ?StalledRunCandidate $expectedCandidate = null,
    ): bool {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException(\sprintf(
                'markTerminal() requires a terminal status; got "%s".',
                $status->value,
            ));
        }
        if ($expectedStatus?->isTerminal() === true) {
            throw new \InvalidArgumentException('markTerminal() expected source status must be non-terminal.');
        }
        if ($expectedCandidate !== null && $expectedStatus !== null
            && $expectedCandidate->sourceStatus !== $expectedStatus) {
            throw new \InvalidArgumentException('markTerminal() source expectations disagree.');
        }

        $fields = [
            'status' => $status->value,
            'finished_at' => $this->formatDateTime($finishedAt),
            'pending_approval_call_id' => null,
            'approval_expires_at' => null,
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

        // C-014 applies even when a caller supplies a source expectation.
        foreach (RunStatus::terminals() as $terminal) {
            $update->condition('status', $terminal->value, '!=');
        }

        $sourceStatus = $expectedStatus;
        if ($expectedCandidate !== null) {
            $sourceStatus = $expectedCandidate->sourceStatus;
        }
        if ($sourceStatus !== null) {
            $update->condition('status', $sourceStatus->value);
        }
        if ($expectedCandidate !== null) {
            $this->applyCandidateConditions($update, $expectedCandidate);
        }

        $affected = $update->execute();

        return $affected === 1;
    }

    public function requestCancellation(string $id, RunStatus $expectedStatus, \DateTimeImmutable $now): bool
    {
        if ($expectedStatus === RunStatus::Queued) {
            $fields = [
                'status' => RunStatus::Cancelled->value,
                'finished_at' => $this->formatDateTime($now),
                'error_code' => 'cancelled_by_user',
                'error_message' => 'Run cancelled before worker pickup.',
            ];
        } elseif ($expectedStatus === RunStatus::Running || $expectedStatus === RunStatus::AwaitingApproval) {
            $fields = ['status' => RunStatus::Cancelling->value];
        } else {
            return false;
        }

        return $this->database->update(self::TABLE)
            ->fields($fields)
            ->condition('id', $id)
            ->condition('status', $expectedStatus->value)
            ->execute() === 1;
    }

    public function grantApproval(string $id, string $callId): bool
    {
        return $this->approvalTransition($id, $callId, [
            'status' => RunStatus::Running->value,
            'pending_approval_call_id' => null,
            'approval_expires_at' => null,
        ]);
    }

    public function denyApproval(string $id, string $callId, \DateTimeImmutable $now): bool
    {
        return $this->approvalTransition($id, $callId, [
            'status' => RunStatus::Failed->value,
            'pending_approval_call_id' => null,
            'approval_expires_at' => null,
            'finished_at' => $this->formatDateTime($now),
            'error_code' => 'approval_denied',
            'error_message' => 'Approval denied by user.',
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function approvalTransition(string $id, string $callId, array $fields): bool
    {
        return $this->database->update(self::TABLE)
            ->fields($fields)
            ->condition('id', $id)
            ->condition('status', RunStatus::AwaitingApproval->value)
            ->condition('pending_approval_call_id', $callId)
            ->execute() === 1;
    }

    /**
     * Find runs whose `status='running'` and `started_at < $threshold`.
     *
     * Used by the reaper to detect worker-crash victims. Backed by
     * `idx_agent_run_status_started_at`.
     *
     * @return list<StalledRunCandidate>
     */
    public function findStuckRunning(\DateTimeImmutable $threshold): array
    {
        $thresholdString = $this->formatDateTime($threshold);

        $rows = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, $this->candidateFields())
            ->condition('status', RunStatus::Running->value)
            ->condition('started_at', $thresholdString, '<')
            ->execute();

        return $this->candidatesFromRows($rows, RunStatus::Running);
    }

    /**
     * Find non-running rows that have exceeded the worker TTL.
     *
     * Queued rows are aged from `queued_at`; cancellation rows from
     * `started_at`; approval rows from their persisted HITL deadline.
     *
     * @return list<StalledRunCandidate>
     */
    public function findAbandoned(\DateTimeImmutable $threshold, \DateTimeImmutable $now): array
    {
        $thresholdString = $this->formatDateTime($threshold);
        $results = [];

        $queuedRows = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, $this->candidateFields())
            ->condition('status', RunStatus::Queued->value)
            ->condition('queued_at', $thresholdString, '<')
            ->execute();
        $results = [...$results, ...$this->candidatesFromRows($queuedRows, RunStatus::Queued)];

        $cancellingRows = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, $this->candidateFields())
            ->condition('status', RunStatus::Cancelling->value)
            ->condition('started_at', $thresholdString, '<')
            ->execute();
        $results = [...$results, ...$this->candidatesFromRows($cancellingRows, RunStatus::Cancelling)];

        $expiredApprovals = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, $this->candidateFields())
            ->condition('status', RunStatus::AwaitingApproval->value)
            ->condition('approval_expires_at', $this->formatDateTime($now), '<')
            ->execute();
        $results = [...$results, ...$this->candidatesFromRows($expiredApprovals, RunStatus::AwaitingApproval)];

        // Upgrade compatibility: rows that entered approval before the deadline
        // column existed retain the old started_at-based TTL until classified.
        $legacyApprovals = $this->database
            ->select(self::TABLE)
            ->fields(self::TABLE, $this->candidateFields())
            ->condition('status', RunStatus::AwaitingApproval->value)
            ->condition('approval_expires_at', null, 'IS NULL')
            ->condition('started_at', $thresholdString, '<')
            ->execute();
        $results = [...$results, ...$this->candidatesFromRows($legacyApprovals, RunStatus::AwaitingApproval)];

        return $results;
    }

    /** @return list<string> */
    private function candidateFields(): array
    {
        return ['id', 'queued_at', 'started_at', 'pending_approval_call_id', 'approval_expires_at'];
    }

    /**
     * @param iterable<object|array<string, mixed>> $rows
     * @return list<StalledRunCandidate>
     */
    private function candidatesFromRows(iterable $rows, RunStatus $sourceStatus): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $id = (string) ($values['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $candidates[] = new StalledRunCandidate(
                id: $id,
                sourceStatus: $sourceStatus,
                queuedAt: isset($values['queued_at']) ? (string) $values['queued_at'] : null,
                startedAt: isset($values['started_at']) ? (string) $values['started_at'] : null,
                pendingApprovalCallId: isset($values['pending_approval_call_id'])
                    ? (string) $values['pending_approval_call_id']
                    : null,
                approvalExpiresAt: isset($values['approval_expires_at'])
                    ? (string) $values['approval_expires_at']
                    : null,
            );
        }

        return $candidates;
    }

    private function applyCandidateConditions(UpdateInterface $update, StalledRunCandidate $candidate): void
    {
        $this->conditionExact($update, 'queued_at', $candidate->queuedAt);
        $this->conditionExact($update, 'started_at', $candidate->startedAt);
        if ($candidate->sourceStatus === RunStatus::AwaitingApproval) {
            $this->conditionExact($update, 'pending_approval_call_id', $candidate->pendingApprovalCallId);
            $this->conditionExact($update, 'approval_expires_at', $candidate->approvalExpiresAt);
        }
    }

    private function conditionExact(UpdateInterface $update, string $field, ?string $value): void
    {
        if ($value === null) {
            $update->condition($field, null, 'IS NULL');

            return;
        }
        $update->condition($field, $value);
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
