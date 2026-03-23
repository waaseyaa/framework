<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;

/**
 * @deprecated Use FailedJobRepositoryInterface with InMemoryFailedJobRepository or DatabaseFailedJobRepository instead.
 */
final class FailedJobRepository implements FailedJobRepositoryInterface
{
    private readonly InMemoryFailedJobRepository $inner;

    public function __construct()
    {
        $this->inner = new InMemoryFailedJobRepository();
    }

    public function record(string $queue, string $payload, \Throwable $e): string
    {
        return $this->inner->record($queue, $payload, $e);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function find(string $id): ?array
    {
        return $this->inner->find($id);
    }

    public function forget(string $id): void
    {
        $this->inner->forget($id);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function retry(string $id): ?array
    {
        return $this->inner->retry($id);
    }
}
