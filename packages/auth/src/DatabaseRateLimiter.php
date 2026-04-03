<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Database\DatabaseInterface;

final class DatabaseRateLimiter implements RateLimiterInterface
{
    private const TABLE = 'rate_limits';

    private bool $tableCreated = false;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function hit(string $key, int $decaySeconds): void
    {
        $this->ensureTable();
        $this->pruneExpired();

        $row = $this->fetchRow($key);

        if ($row === null) {
            $this->database->insert(self::TABLE)
                ->values([
                    'key' => $key,
                    'count' => 1,
                    'reset_at' => time() + $decaySeconds,
                ])
                ->execute();
        } else {
            $this->database->update(self::TABLE)
                ->fields(['count' => (int) $row['count'] + 1])
                ->condition('key', $key)
                ->execute();
        }
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function attempts(string $key): int
    {
        $this->ensureTable();
        $this->pruneExpired();

        $row = $this->fetchRow($key);

        return $row !== null ? (int) $row['count'] : 0;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function clear(string $key): void
    {
        $this->ensureTable();

        $this->database->delete(self::TABLE)
            ->condition('key', $key)
            ->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $key): ?array
    {
        foreach ($this->database->select(self::TABLE)->condition('key', $key)->execute() as $row) {
            return $row;
        }

        return null;
    }

    private function pruneExpired(): void
    {
        $this->database->delete(self::TABLE)
            ->condition('reset_at', time(), '<=')
            ->execute();
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->database->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                count INTEGER NOT NULL DEFAULT 0,
                reset_at INTEGER NOT NULL
            )
        SQL);

        $this->tableCreated = true;
    }
}
