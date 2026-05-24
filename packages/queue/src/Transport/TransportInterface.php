<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

/**
 * Abstraction for queue transport backends.
 *
 * Handles the low-level storage and retrieval of serialized job payloads.
 *
 * @internal
 * @api
 */
interface TransportInterface
{
    /**
     * Push a serialized payload onto the queue.
     *
     * @param int $delay Seconds before the job becomes available
     */
    public function push(string $queue, string $payload, int $delay = 0): void;

    /**
     * Pop the next available job from the queue.
     *
     * Returns null if no jobs are available. The returned array contains
     * 'id', 'payload', and 'attempts' keys. The job is reserved (locked)
     * until ack'd, rejected, or released.
     *
     * @return array{id: int|string, payload: string, attempts: int}|null
     */
    public function pop(string $queue): ?array;

    /**
     * Acknowledge successful processing — removes the job permanently.
     */
    public function ack(int|string $jobId): void;

    /**
     * Reject a job — removes it from the queue (caller handles failure recording).
     */
    public function reject(int|string $jobId): void;

    /**
     * Release a job back to the queue with an optional delay.
     */
    public function release(int|string $jobId, int $delay = 0): void;

    /**
     * Get the number of pending jobs in the queue.
     */
    public function size(string $queue): int;

    /**
     * Remove all jobs from the queue.
     */
    public function purge(string $queue): void;

    /**
     * List jobs for the admin queue dashboard (M4B follow-up, GitHub #1576).
     *
     * Returns a window of jobs ordered by id ASC plus the total matching the
     * filter (so callers can paginate without re-walking the table). The
     * derived `status` column is `'queued'` when `reserved_at IS NULL` and
     * `'in_progress'` otherwise. `$status` filters server-side:
     *
     *   - `'queued'`        → only rows with `reserved_at IS NULL`
     *   - `'in_progress'`   → only rows with `reserved_at IS NOT NULL`
     *   - `null`            → both (no filter)
     *
     * Implementations MUST NOT include failed jobs here — failed jobs live
     * in `FailedJobRepositoryInterface` and are surfaced separately by the
     * admin queue controller.
     *
     * @param int         $limit  Max rows in the data window (>= 0)
     * @param int         $offset Zero-based offset into the filtered result set
     * @param string|null $status Optional status filter — implementations MUST
     *                            accept `'queued'`, `'in_progress'`, or `null`
     *                            and MUST throw `\InvalidArgumentException`
     *                            for any other non-null value.
     *
     * @return array{
     *   data: list<array{
     *     id: int|string,
     *     queue: string,
     *     payload: string,
     *     attempts: int,
     *     available_at: int,
     *     reserved_at: int|null,
     *     status: 'queued'|'in_progress'
     *   }>,
     *   total: int
     * }
     *
     * @api
     */
    public function listJobs(int $limit, int $offset = 0, ?string $status = null): array;
}
