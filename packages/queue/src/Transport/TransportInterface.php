<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

/**
 * Abstraction for queue transport backends.
 *
 * Handles the low-level storage and retrieval of serialized job payloads.
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
}
