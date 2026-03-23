<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lock;

use Waaseyaa\Database\DatabaseInterface;

final class DatabaseLock implements LockInterface
{
    private const TABLE = 'waaseyaa_schedule_locks';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function acquire(string $name, int $ttl = 300): bool
    {
        $now = time();

        // Clean up expired locks
        $this->database->delete(self::TABLE)
            ->condition('expires_at', $now, '<=')
            ->execute();

        // Atomic acquire: INSERT and catch duplicate key violation
        try {
            $this->database->insert(self::TABLE)
                ->values([
                    'task_name' => $name,
                    'locked_at' => $now,
                    'expires_at' => $now + $ttl,
                ])
                ->execute();

            return true;
        } catch (\Throwable) {
            // Duplicate key = lock already held
            return false;
        }
    }

    public function release(string $name): void
    {
        $this->database->delete(self::TABLE)
            ->condition('task_name', $name)
            ->execute();
    }
}
