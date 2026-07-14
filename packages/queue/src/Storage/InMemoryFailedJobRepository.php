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

    /** @var array<string, true> */
    private array $retryClaims = [];

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
        unset($this->retryClaims[$id]);
    }

    public function flush(): void
    {
        $this->records = [];
        $this->sequence = 0;
        $this->retryClaims = [];
    }

    public function retry(string $id): ?array
    {
        $record = $this->records[$id] ?? null;
        if ($record !== null && $this->claimForRetry($id)) {
            $this->forget($id);

            return $record;
        }

        return null;
    }

    public function claimForRetry(string $id): bool
    {
        if (!isset($this->records[$id]) || isset($this->retryClaims[$id])) {
            return false;
        }
        $this->retryClaims[$id] = true;

        return true;
    }

    public function releaseRetryClaim(string $id): void
    {
        unset($this->retryClaims[$id]);
    }
}
