<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

use Waaseyaa\Database\DatabaseInterface;

/**
 * DBAL-backed queue transport using the waaseyaa_queue_jobs table.
 *
 * Jobs are claimed atomically via SELECT + conditional UPDATE to prevent
 * duplicate processing in multi-worker environments.
 *
 * **Lease / visibility timeout (crash recovery).** A claim records the wall-clock
 * time in `reserved_at`; the claim is treated as a lease that expires after
 * {@see $visibilityTimeout} seconds. If a worker dies (SIGKILL / OOM / reboot)
 * between claiming and ack/release, the row would otherwise stay reserved forever
 * — never retried, never failed. {@see pop()} therefore also reclaims rows whose
 * lease has expired, bumping `attempts` on reclaim so the worker's
 * max-attempts / failed-job path still terminates an always-crashing job (no
 * infinite reclaim loop). The reclaim re-uses the existing `reserved_at` column
 * (no schema change): the lease deadline is `reserved_at + visibilityTimeout`.
 */
final class DbalTransport implements TransportInterface
{
    private const TABLE = 'waaseyaa_queue_jobs';

    /**
     * @param int $visibilityTimeout Seconds a claim is held before its lease is
     *                               considered expired and the job is reclaimable
     *                               by another worker. Configurable via
     *                               `queue.visibility_timeout`.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly int $visibilityTimeout = 90,
    ) {}

    public function push(string $queue, string $payload, int $delay = 0): void
    {
        $this->database->insert(self::TABLE)
            ->values([
                'queue' => $queue,
                'payload' => $payload,
                'attempts' => 0,
                'available_at' => time() + $delay,
                'reserved_at' => null,
                'created_at' => time(),
            ])
            ->execute();
    }

    public function pop(string $queue): ?array
    {
        $now = time();
        $expiredBefore = $now - $this->visibilityTimeout;

        // Atomic claim: find the next claimable job, then conditionally UPDATE to
        // reserve it. If another worker (re)claimed it between SELECT and UPDATE,
        // the UPDATE affects 0 rows and we retry.
        for ($i = 0; $i < 3; $i++) {
            // Claimable = never reserved (reserved_at IS NULL) OR a lease that has
            // expired (reserved_at <= now - visibilityTimeout). COALESCE folds both
            // into one condition: a fresh row's NULL becomes 0, and `expiredBefore`
            // is a real unix timestamp, so fresh rows always qualify while a live
            // lease (reserved_at > expiredBefore) does not.
            $rows = $this->database->select(self::TABLE, 'qj')
                ->fields('qj', ['id', 'reserved_at', 'attempts'])
                ->condition('queue', $queue)
                ->condition('available_at', $now, '<=')
                ->condition('COALESCE(reserved_at, 0)', $expiredBefore, '<=')
                ->orderBy('id', 'ASC')
                ->range(0, 1)
                ->execute();

            $candidate = null;
            foreach ($rows as $row) {
                $candidate = $row;
                break;
            }

            if ($candidate === null) {
                return null;
            }

            $candidateId = $candidate['id'];
            $reservedAt = $candidate['reserved_at'];

            if ($reservedAt === null) {
                // Fresh claim: succeeds only if still unreserved (race guard).
                $affected = $this->database->update(self::TABLE)
                    ->fields(['reserved_at' => $now])
                    ->condition('id', $candidateId)
                    ->condition('reserved_at', null, 'IS NULL')
                    ->execute();
            } else {
                // Reclaim an expired lease — the prior worker died after claiming.
                // Bump attempts so the worker's max-attempts / failed-job path still
                // terminates an always-crashing job (no infinite reclaim loop).
                // Guard on the exact prior reserved_at so a concurrent reclaimer
                // loses the race (affected = 0 → retry) — atomicity preserved.
                $affected = $this->database->update(self::TABLE)
                    ->fields([
                        'reserved_at' => $now,
                        'attempts' => (int) $candidate['attempts'] + 1,
                    ])
                    ->condition('id', $candidateId)
                    ->condition('reserved_at', (int) $reservedAt)
                    ->execute();
            }

            if ($affected === 0) {
                continue; // Another worker (re)claimed it, retry
            }

            // Fetch the full job data (attempts reflects any reclaim bump).
            $jobRows = $this->database->select(self::TABLE, 'qj')
                ->fields('qj', ['id', 'payload', 'attempts'])
                ->condition('id', $candidateId)
                ->execute();

            foreach ($jobRows as $job) {
                return [
                    'id' => (int) $job['id'],
                    'payload' => $job['payload'],
                    'attempts' => (int) $job['attempts'],
                ];
            }
        }

        return null;
    }

    public function ack(int|string $jobId): void
    {
        $this->database->delete(self::TABLE)
            ->condition('id', $jobId)
            ->execute();
    }

    public function reject(int|string $jobId): void
    {
        $this->database->delete(self::TABLE)
            ->condition('id', $jobId)
            ->execute();
    }

    public function release(int|string $jobId, int $delay = 0): void
    {
        $this->database->update(self::TABLE)
            ->fields([
                'reserved_at' => null,
                'available_at' => time() + $delay,
                'attempts' => $this->getAttempts($jobId) + 1,
            ])
            ->condition('id', $jobId)
            ->execute();
    }

    public function size(string $queue): int
    {
        $rows = $this->database->select(self::TABLE, 'qj')
            ->condition('queue', $queue)
            ->isNull('reserved_at')
            ->countQuery()
            ->execute();

        foreach ($rows as $row) {
            return (int) (reset($row));
        }

        return 0;
    }

    public function purge(string $queue): void
    {
        $this->database->delete(self::TABLE)
            ->condition('queue', $queue)
            ->execute();
    }

    public function listJobs(int $limit, int $offset = 0, ?string $status = null): array
    {
        if ($status !== null && $status !== 'queued' && $status !== 'in_progress') {
            throw new \InvalidArgumentException(
                sprintf('listJobs() $status must be "queued", "in_progress", or null; got "%s".', $status),
            );
        }

        $limit = max(0, $limit);
        $offset = max(0, $offset);

        // Total count first, using the same filter as the data query so the
        // caller can paginate without re-walking the table.
        $countQuery = $this->database->select(self::TABLE, 'qj');
        $this->applyStatusFilter($countQuery, $status);
        $countRows = $countQuery->countQuery()->execute();

        $total = 0;
        foreach ($countRows as $row) {
            $total = (int) reset($row);
            break;
        }

        if ($limit === 0 || $total === 0) {
            return ['data' => [], 'total' => $total];
        }

        $dataQuery = $this->database->select(self::TABLE, 'qj')
            ->fields('qj', ['id', 'queue', 'payload', 'attempts', 'available_at', 'reserved_at']);
        $this->applyStatusFilter($dataQuery, $status);
        $dataRows = $dataQuery
            ->orderBy('id', 'ASC')
            ->range($offset, $limit)
            ->execute();

        $data = [];
        foreach ($dataRows as $row) {
            $reservedAt = $row['reserved_at'];
            $reservedAtInt = $reservedAt === null ? null : (int) $reservedAt;
            $data[] = [
                'id' => (int) $row['id'],
                'queue' => (string) $row['queue'],
                'payload' => (string) $row['payload'],
                'attempts' => (int) $row['attempts'],
                'available_at' => (int) $row['available_at'],
                'reserved_at' => $reservedAtInt,
                'status' => $reservedAtInt === null ? 'queued' : 'in_progress',
            ];
        }

        return ['data' => $data, 'total' => $total];
    }

    /**
     * Apply the status filter to a select query in place. Centralised so the
     * COUNT and the data SELECT see exactly the same WHERE clause.
     *
     * @param 'queued'|'in_progress'|null $status
     */
    private function applyStatusFilter(object $query, ?string $status): void
    {
        if ($status === 'queued') {
            $query = $query->isNull('reserved_at');

            return;
        }
        if ($status === 'in_progress') {
            $query = $query->isNotNull('reserved_at');
        }
    }

    private function getAttempts(int|string $jobId): int
    {
        $rows = $this->database->select(self::TABLE, 'qj')
            ->fields('qj', ['attempts'])
            ->condition('id', $jobId)
            ->execute();

        foreach ($rows as $row) {
            return (int) $row['attempts'];
        }

        return 0;
    }
}
