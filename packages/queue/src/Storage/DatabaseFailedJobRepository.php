<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Storage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;

/**
 * Database-backed repository for storing and retrieving failed job records.
 *
 * Uses the waaseyaa_failed_jobs table via DatabaseInterface.
 */
final class DatabaseFailedJobRepository implements FailedJobRepositoryInterface
{
    private const TABLE = 'waaseyaa_failed_jobs';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function record(string $queue, string $payload, \Throwable $e): string
    {
        $id = $this->database->insert(self::TABLE)
            ->values([
                'queue' => $queue,
                'payload' => $payload,
                'exception' => $e::class . ': ' . $e->getMessage(),
                'failed_at' => date('Y-m-d\TH:i:sP'),
            ])
            ->execute();

        return (string) $id;
    }

    public function all(): array
    {
        $result = [];
        $rows = $this->database->select(self::TABLE, 'fj')
            ->fields('fj', ['id', 'queue', 'payload', 'exception', 'failed_at'])
            ->execute();

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $result[$id] = [
                'id' => $id,
                'queue' => $row['queue'],
                'payload' => $row['payload'],
                'exception' => $row['exception'],
                'failed_at' => $row['failed_at'],
            ];
        }

        return $result;
    }

    public function find(string $id): ?array
    {
        $rows = $this->database->select(self::TABLE, 'fj')
            ->fields('fj', ['id', 'queue', 'payload', 'exception', 'failed_at'])
            ->condition('id', $id)
            ->execute();

        foreach ($rows as $row) {
            return [
                'id' => (string) $row['id'],
                'queue' => $row['queue'],
                'payload' => $row['payload'],
                'exception' => $row['exception'],
                'failed_at' => $row['failed_at'],
            ];
        }

        return null;
    }

    public function forget(string $id): void
    {
        $this->database->delete(self::TABLE)
            ->condition('id', $id)
            ->execute();
    }

    public function flush(): void
    {
        $this->database->delete(self::TABLE)
            ->execute();
    }

    public function retry(string $id): ?array
    {
        $record = $this->find($id);
        if ($record !== null) {
            $this->database->update(self::TABLE)
                ->fields(['retried_at' => date('Y-m-d\TH:i:sP')])
                ->condition('id', $id)
                ->execute();
            $this->forget($id);
        }

        return $record;
    }
}
