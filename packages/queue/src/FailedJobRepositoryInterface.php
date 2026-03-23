<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

interface FailedJobRepositoryInterface
{
    /**
     * Record a failed job.
     *
     * @return string The ID of the recorded failure
     */
    public function record(string $queue, string $payload, \Throwable $e): string;

    /**
     * Retrieve all failed job records.
     *
     * @return array<string, array{id: string, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array;

    /**
     * Find a specific failed job record by its ID.
     *
     * @return array{id: string, queue: string, payload: string, exception: string, failed_at: string}|null
     */
    public function find(string $id): ?array;

    /**
     * Remove a single failed job record by its ID.
     */
    public function forget(string $id): void;

    /**
     * Remove all failed job records.
     */
    public function flush(): void;

    /**
     * Retrieve and remove a failed job record for retry.
     *
     * @return array{id: string, queue: string, payload: string, exception: string, failed_at: string}|null
     */
    public function retry(string $id): ?array;
}
