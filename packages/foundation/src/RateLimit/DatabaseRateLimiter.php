<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\RateLimit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\SchemaRequirement;

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
 * `HttpKernel` constructs this implementation with its canonical database for
 * the default HTTP rate-limit boundary. {@see InMemoryRateLimiter} remains
 * available for isolated tests and deliberately process-local consumers.
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

        // Retry only a lost compare-and-set or a raced first insertion. Lock
        // failures (including non-waitable snapshot upgrades) propagate unchanged.
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            $row = $this->fetchRow($key);
            if ($row === null) {
                try {
                    $this->database->insert(self::TABLE)
                        ->values(['key' => $key, 'count' => 1, 'window_start' => $now])
                        ->execute();
                } catch (UniqueConstraintViolationException $collision) {
                    // PostgreSQL aborts a transaction after a constraint error;
                    // never pretend a caller-owned transaction can be replayed.
                    if (!$this->database instanceof DBALDatabase
                        || $this->database->getConnection()->isTransactionActive()
                    ) {
                        throw $collision;
                    }
                    continue;
                }
                return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retryAfter' => null];
            }

            $windowEnd = (int) $row['window_start'] + $windowSeconds;
            $expired = $now >= $windowEnd;
            $count = $expired ? 1 : (int) $row['count'] + 1;
            $changed = $this->database->update(self::TABLE)
                ->fields(['count' => $count, 'window_start' => $expired ? $now : (int) $row['window_start']])
                ->condition('key', $key)
                ->condition('count', $row['count'])
                ->condition('window_start', $row['window_start'])
                ->execute();
            if ($changed === 0) {
                continue;
            }
            return !$expired && $count > $maxAttempts
                ? ['allowed' => false, 'remaining' => 0, 'retryAfter' => $windowEnd - $now]
                : ['allowed' => true, 'remaining' => $maxAttempts - $count, 'retryAfter' => null];
        }
        throw new \RuntimeException('Rate-limit window remained contended after 32 accounting attempts.');
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

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['key', 'count', 'window_start'],
            'waaseyaa/foundation:2026_08_12_000001_rate_limit_window_schema',
        );

        $this->tableEnsured = true;
    }
}
