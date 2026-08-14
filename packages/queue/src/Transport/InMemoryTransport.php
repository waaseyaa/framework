<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

/**
 * In-memory transport for testing.
 * @api
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
        $this->requeue($jobId, $delay, true);
    }

    public function defer(int|string $jobId, int $delay = 0): void
    {
        $this->requeue($jobId, $delay, false);
    }

    private function requeue(int|string $jobId, int $delay, bool $incrementAttempts): void
    {
        $job = $this->reserved[$jobId] ?? null;
        if ($job === null) {
            return;
        }

        unset($this->reserved[$jobId]);

        $this->queues[$job['queue']][] = [
            'id' => (int) $jobId,
            'payload' => $job['payload'],
            'attempts' => $job['attempts'] + ($incrementAttempts ? 1 : 0),
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

    public function listJobs(int $limit, int $offset = 0, ?string $status = null): array
    {
        if ($status !== null && $status !== 'queued' && $status !== 'in_progress') {
            throw new \InvalidArgumentException(
                sprintf('listJobs() $status must be "queued", "in_progress", or null; got "%s".', $status),
            );
        }

        $limit = max(0, $limit);
        $offset = max(0, $offset);
        $now = time();

        $rows = [];

        if ($status !== 'in_progress') {
            foreach ($this->queues as $queueName => $jobs) {
                foreach ($jobs as $job) {
                    $rows[] = [
                        'id' => $job['id'],
                        'queue' => $queueName,
                        'payload' => $job['payload'],
                        'attempts' => $job['attempts'],
                        'available_at' => $job['available_at'],
                        'reserved_at' => null,
                        'status' => 'queued',
                    ];
                }
            }
        }

        if ($status !== 'queued') {
            foreach ($this->reserved as $jobId => $job) {
                $rows[] = [
                    'id' => $jobId,
                    'queue' => $job['queue'],
                    'payload' => $job['payload'],
                    'attempts' => $job['attempts'],
                    // The in-memory implementation does not carry the original
                    // available_at for reserved jobs (pop() doesn't preserve it);
                    // surface "now" so the row shape stays stable for consumers.
                    'available_at' => $now,
                    'reserved_at' => $now,
                    'status' => 'in_progress',
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        $total = count($rows);

        if ($limit === 0 || $total === 0) {
            return ['data' => [], 'total' => $total];
        }

        $data = array_slice($rows, $offset, $limit);

        return ['data' => $data, 'total' => $total];
    }
}
