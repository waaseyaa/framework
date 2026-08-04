<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Database\DatabaseInterface;

/**
 * @api
 */
final class DatabaseRateLimiter implements AtomicRateLimiterInterface
{
    private const TABLE = 'rate_limits';

    private bool $tableCreated = false;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if ($key === '' || \strlen($key) > 255) {
            throw new \InvalidArgumentException('A rate-limit key must contain 1-255 bytes.');
        }
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new \InvalidArgumentException('Rate-limit bounds must be positive integers.');
        }

        $this->ensureTable();
        $now = \time();
        $table = $this->database->quoteIdentifier(self::TABLE);
        $bucket = $this->database->quoteIdentifier('bucket_key');
        $hits = $this->database->quoteIdentifier('hits');
        $reset = $this->database->quoteIdentifier('reset_at');

        // The upsert atomically consumes the attempt before any decision is
        // read. Concurrent requests therefore cannot all observe the same
        // pre-increment count and over-admit the bucket. The following read can
        // only see this count or a later/higher one, so a race may conservatively
        // refuse an attempt but can never admit more than the configured limit.
        $sql = "INSERT INTO {$table} ({$bucket}, {$hits}, {$reset}) VALUES (?, 1, ?) "
            . "ON CONFLICT ({$bucket}) DO UPDATE SET "
            . "{$hits} = CASE WHEN {$reset} <= ? THEN 1 ELSE {$hits} + 1 END, "
            . "{$reset} = CASE WHEN {$reset} <= ? THEN excluded.{$reset} ELSE {$reset} END";

        $this->database->query(
            $sql,
            [$key, $now + $decaySeconds, $now, $now],
        );
        $row = $this->fetchRow($key);
        if ($row === null || !isset($row['hits'])) {
            throw new \RuntimeException('The rate limiter did not return an atomic decision.');
        }

        return (int) $row['hits'] <= $maxAttempts;
    }

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
