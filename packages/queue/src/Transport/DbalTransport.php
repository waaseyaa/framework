<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Transport;

use Waaseyaa\Database\DatabaseInterface;

/**
 * DBAL-backed queue transport using the waaseyaa_queue_jobs table.
 *
 * Jobs are claimed atomically via SELECT + UPDATE within a transaction
 * to prevent duplicate processing in multi-worker environments.
 */
final class DbalTransport implements TransportInterface
{
    private const TABLE = 'waaseyaa_queue_jobs';

    public function __construct(
        private readonly DatabaseInterface $database,
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

        // Atomic claim: find the next available job ID, then UPDATE with
        // conditions to reserve it. If another worker claimed it between
        // SELECT and UPDATE, the UPDATE affects 0 rows and we retry.
        for ($i = 0; $i < 3; $i++) {
            $rows = $this->database->select(self::TABLE, 'qj')
                ->fields('qj', ['id'])
                ->condition('queue', $queue)
                ->condition('available_at', $now, '<=')
                ->isNull('reserved_at')
                ->orderBy('id', 'ASC')
                ->range(0, 1)
                ->execute();

            $candidateId = null;
            foreach ($rows as $row) {
                $candidateId = $row['id'];
                break;
            }

            if ($candidateId === null) {
                return null;
            }

            // Atomically reserve: only succeeds if still unreserved
            $affected = $this->database->update(self::TABLE)
                ->fields(['reserved_at' => $now])
                ->condition('id', $candidateId)
                ->condition('reserved_at', null, 'IS NULL')
                ->execute();

            if ($affected === 0) {
                continue; // Another worker claimed it, retry
            }

            // Fetch the full job data
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
