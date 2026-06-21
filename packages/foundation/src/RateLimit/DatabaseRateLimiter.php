<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\RateLimit;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Persistent, cross-request {@see RateLimiterInterface} backed by a database
 * table (#1611).
 *
 * {@see InMemoryRateLimiter} keeps its window map in a PHP array that lives only
 * for the current request, so under php-fpm / FrankenPHP — where the kernel is
 * rebuilt per request — it resets every request and never actually limits across
 * them. This implementation records each fixed window as a row in
 * `rate_limit_windows` through the kernel's persistent {@see DatabaseInterface},
 * so the count survives across requests AND workers (the SQL store is shared).
 * The fixed-window semantics are identical to {@see InMemoryRateLimiter}: a
 * window opens on the first attempt for a key, counts increment within it, the
 * limit is exceeded once the count passes `maxAttempts`, and the window resets
 * after `windowSeconds`.
 *
 * The shipped default binding stays {@see InMemoryRateLimiter} (it needs no
 * writable table); bind {@see RateLimiterInterface} to this in an app service
 * provider — passing the kernel's persistent `DatabaseInterface` — when a limit
 * must hold across requests.
 *
 * @api
 */
final class DatabaseRateLimiter implements RateLimiterInterface
{
    private const TABLE = 'rate_limit_windows';

    private bool $tableEnsured = false;

    /**
     * @param (\Closure(): int)|null $clock Override `time()` (unix seconds) —
     *        tests inject a fake clock to exercise window expiry deterministically.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?\Closure $clock = null,
    ) {}

    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $this->ensureTable();
        $now = ($this->clock ?? static fn(): int => time())();

        $row = $this->fetchRow($key);

        // First attempt for this key, or a window that has fully expired: open a
        // fresh window with count = 1.
        if ($row === null) {
            $this->openWindow($key, $now, insert: true);

            return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retryAfter' => null];
        }

        $windowEnd = (int) $row['window_start'] + $windowSeconds;

        if ($now >= $windowEnd) {
            $this->openWindow($key, $now, insert: false);

            return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retryAfter' => null];
        }

        // Window still active — increment.
        $count = (int) $row['count'] + 1;
        $this->database->update(self::TABLE)
            ->fields(['count' => $count])
            ->condition('key', $key)
            ->execute();

        if ($count > $maxAttempts) {
            return ['allowed' => false, 'remaining' => 0, 'retryAfter' => $windowEnd - $now];
        }

        return ['allowed' => true, 'remaining' => $maxAttempts - $count, 'retryAfter' => null];
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

    private function openWindow(string $key, int $now, bool $insert): void
    {
        if ($insert) {
            $this->database->insert(self::TABLE)
                ->values(['key' => $key, 'count' => 1, 'window_start' => $now])
                ->execute();

            return;
        }

        $this->database->update(self::TABLE)
            ->fields(['count' => 1, 'window_start' => $now])
            ->condition('key', $key)
            ->execute();
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->database->query(<<<'SQL'
                CREATE TABLE IF NOT EXISTS rate_limit_windows (
                    key TEXT PRIMARY KEY,
                    count INTEGER NOT NULL DEFAULT 0,
                    window_start INTEGER NOT NULL
                )
            SQL);

        $this->tableEnsured = true;
    }
}
