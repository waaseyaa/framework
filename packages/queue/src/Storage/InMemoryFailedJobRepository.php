<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Storage;

use Waaseyaa\Queue\FailedJobRepositoryInterface;

/**
 * In-memory repository for storing and retrieving failed job records.
 *
 * Suitable for testing and development environments.
 */
final class InMemoryFailedJobRepository implements FailedJobRepositoryInterface
{
    /** @var array<string, array{id: string, queue: string, payload: string, exception: string, failed_at: string}> */
    private array $records = [];

    private int $sequence = 0;

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

    public function all(): array
    {
        return $this->records;
    }

    public function find(string $id): ?array
    {
        return $this->records[$id] ?? null;
    }

    public function forget(string $id): void
    {
        unset($this->records[$id]);
    }

    public function flush(): void
    {
        $this->records = [];
        $this->sequence = 0;
    }

    public function retry(string $id): ?array
    {
        $record = $this->records[$id] ?? null;
        if ($record !== null) {
            unset($this->records[$id]);
        }

        return $record;
    }
}
