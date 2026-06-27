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

    public function acquire(string $name, int $ttl = 300): ?string
    {
        $now = time();
        $token = bin2hex(random_bytes(16));

        // Clean up expired locks (a stale holder whose lease has timed out).
        $this->database->delete(self::TABLE)
            ->condition('expires_at', $now, '<=')
            ->execute();

        // Atomic acquire: INSERT and catch the duplicate-key violation. The owner
        // token is persisted (locked_by) so only this acquirer can release it.
        try {
            $this->database->insert(self::TABLE)
                ->values([
                    'task_name' => $name,
                    'locked_at' => $now,
                    'expires_at' => $now + $ttl,
                    'locked_by' => $token,
                ])
                ->execute();

            return $token;
        } catch (\Throwable) {
            // Duplicate key = lock already held
            return null;
        }
    }

    public function release(string $name, string $token): void
    {
        // Ownership-scoped: only delete the row this caller still owns. If the
        // lease expired mid-run and another node reclaimed it (new locked_by),
        // this matches no row and deletes nothing — so a stale holder cannot tear
        // down the new owner's live lock (the scheduler m15 split-brain hole).
        $this->database->delete(self::TABLE)
            ->condition('task_name', $name)
            ->condition('locked_by', $token)
            ->execute();
    }
}
