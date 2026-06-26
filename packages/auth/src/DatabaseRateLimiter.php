<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Database\DatabaseInterface;

/**
 * @api
 */
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

        // Ensure a row exists for this key (starts at 0; tolerate a concurrent insert).
        try {
            $this->database->insert(self::TABLE)
                ->values([
                    'bucket_key' => $key,
                    'hits' => 0,
                    'reset_at' => time() + $decaySeconds,
                ])
                ->execute();
        } catch (\Throwable $e) {
            // A concurrent hit() (or a prior hit in this window) already inserted the
            // key — benign; the atomic increment below still counts this hit. Only a
            // genuinely-absent row after the failure is a real error.
            if ($this->fetchRow($key) === null) {
                throw $e;
            }
        }

        // Atomic increment — no read-modify-write, so concurrent hits never undercount.
        $col = $this->database->quoteIdentifier('hits');
        $sql = 'UPDATE ' . $this->database->quoteIdentifier(self::TABLE)
            . ' SET ' . $col . ' = ' . $col . ' + 1'
            . ' WHERE ' . $this->database->quoteIdentifier('bucket_key') . ' = ?';
        $this->database->query($sql, [$key]);
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

        return $row !== null ? (int) $row['hits'] : 0;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function clear(string $key): void
    {
        $this->ensureTable();

        $this->database->delete(self::TABLE)
            ->condition('bucket_key', $key)
            ->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $key): ?array
    {
        foreach ($this->database->select(self::TABLE)->condition('bucket_key', $key)->execute() as $row) {
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

        $schema = $this->database->schema();
        if (!$schema->tableExists(self::TABLE)) {
            try {
                $schema->createTable(self::TABLE, [
                    'fields' => [
                        'bucket_key' => ['type' => 'text', 'not null' => true],
                        'hits' => ['type' => 'integer', 'not null' => true],
                        'reset_at' => ['type' => 'integer', 'not null' => true],
                    ],
                    'primary key' => ['bucket_key'],
                ]);
            } catch (\Throwable $e) {
                // A concurrent boot created the table between our check and create.
                if (!$this->database->schema()->tableExists(self::TABLE)) {
                    throw $e;
                }
            }
        }

        $this->tableCreated = true;
    }
}
