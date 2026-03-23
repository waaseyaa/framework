<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

/**
 * In-memory transport for testing.
 */
final class InMemoryTransport implements TransportInterface
{
    /** @var array<string, list<array{id: int, payload: string, attempts: int, available_at: int}>> */
    private array $queues = [];

    /** @var array<int, array{queue: string, payload: string, attempts: int}> */
    private array $reserved = [];

    private int $sequence = 0;

    public function push(string $queue, string $payload, int $delay = 0): void
    {
        $this->queues[$queue][] = [
            'id' => ++$this->sequence,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => time() + $delay,
        ];
    }

    public function pop(string $queue): ?array
    {
        $now = time();

        foreach ($this->queues[$queue] ?? [] as $index => $job) {
            if ($job['available_at'] <= $now) {
                unset($this->queues[$queue][$index]);
                $this->queues[$queue] = array_values($this->queues[$queue]);

                $this->reserved[$job['id']] = [
                    'queue' => $queue,
                    'payload' => $job['payload'],
                    'attempts' => $job['attempts'],
                ];

                return [
                    'id' => $job['id'],
                    'payload' => $job['payload'],
                    'attempts' => $job['attempts'],
                ];
            }
        }

        return null;
    }

    public function ack(int|string $jobId): void
    {
        unset($this->reserved[$jobId]);
    }

    public function reject(int|string $jobId): void
    {
        unset($this->reserved[$jobId]);
    }

    public function release(int|string $jobId, int $delay = 0): void
    {
        $job = $this->reserved[$jobId] ?? null;
        if ($job === null) {
            return;
        }

        unset($this->reserved[$jobId]);

        $this->queues[$job['queue']][] = [
            'id' => (int) $jobId,
            'payload' => $job['payload'],
            'attempts' => $job['attempts'] + 1,
            'available_at' => time() + $delay,
        ];
    }

    public function size(string $queue): int
    {
        return count($this->queues[$queue] ?? []);
    }

    public function purge(string $queue): void
    {
        $this->queues[$queue] = [];
    }

    /**
     * Get all pushed payloads for a queue (test helper).
     *
     * @return list<array{queue: string, payload: string, attempts: int}>
     */
    public function getReserved(): array
    {
        return array_values($this->reserved);
    }
}
