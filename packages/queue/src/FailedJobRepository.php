<?php

declare(strict_types=1);

namespace Aurora\Queue;

/**
 * In-memory repository for storing and retrieving failed job records.
 *
 * Each record contains the queue name, serialised payload, exception
 * details, and a unique identifier.
 */
final class FailedJobRepository
{
    /** @var array<string, array{id: string, queue: string, payload: string, exception: string, failed_at: string}> */
    private array $records = [];

    private int $sequence = 0;

    /**
     * Record a failed job.
     */
    public function record(string $queue, string $payload, \Throwable $e): string
    {
        $id = (string) ++$this->sequence;
        $this->records[$id] = [
            'id' => $id,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => $e::class . ': ' . $e->getMessage(),
            'failed_at' => date('Y-m-d\TH:i:sP'),
        ];

        return $id;
    }

    /**
     * Retrieve all failed job records.
     *
     * @return array<string, array{id: string, queue: string, payload: string, exception: string, failed_at: string}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * Find a specific failed job record by its ID.
     *
     * @return array{id: string, queue: string, payload: string, exception: string, failed_at: string}|null
     */
    public function find(string $id): ?array
    {
        return $this->records[$id] ?? null;
    }

    /**
     * Remove a single failed job record by its ID.
     */
    public function forget(string $id): void
    {
        unset($this->records[$id]);
    }

    /**
     * Remove all failed job records.
     */
    public function flush(): void
    {
        $this->records = [];
        $this->sequence = 0;
    }
}
