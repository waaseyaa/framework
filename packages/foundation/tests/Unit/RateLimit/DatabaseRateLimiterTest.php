<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter;

#[CoversClass(DatabaseRateLimiter::class)]
final class DatabaseRateLimiterTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/waaseyaa-ratelimit-' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->dbPath !== '' && is_file($this->dbPath . $suffix)) {
                @unlink($this->dbPath . $suffix);
            }
        }
    }

    #[Test]
    public function allows_first_attempt(): void
    {
        $limiter = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath));

        $result = $limiter->attempt('key1', 5, 60);

        self::assertTrue($result['allowed']);
        self::assertSame(4, $result['remaining']);
        self::assertNull($result['retryAfter']);
    }

    #[Test]
    public function tracks_remaining_then_denies_with_retry_after(): void
    {
        $limiter = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath));

        self::assertSame(2, $limiter->attempt('key1', 3, 60)['remaining']);
        self::assertSame(1, $limiter->attempt('key1', 3, 60)['remaining']);
        self::assertSame(0, $limiter->attempt('key1', 3, 60)['remaining']);

        $denied = $limiter->attempt('key1', 3, 60);
        self::assertFalse($denied['allowed']);
        self::assertSame(0, $denied['remaining']);
        self::assertIsInt($denied['retryAfter']);
        self::assertGreaterThan(0, $denied['retryAfter']);
    }

    #[Test]
    public function isolates_keys(): void
    {
        $limiter = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath));

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('key1', 3, 60);
        }

        $result = $limiter->attempt('key2', 3, 60);
        self::assertTrue($result['allowed']);
        self::assertSame(2, $result['remaining']);
    }

    /**
     * The regression that motivated #1611: a rate limit MUST hold across
     * requests. Two limiters on two SEPARATE connections to the same file DB
     * (standing in for two php-fpm/FrankenPHP requests) must share the count —
     * an InMemoryRateLimiter would let the second "request" start fresh and the
     * limit would never bite.
     */
    #[Test]
    public function limit_persists_across_separate_connections(): void
    {
        $writer = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath));
        for ($i = 0; $i < 3; $i++) {
            $writer->attempt('shared', 3, 60);
        }

        // A brand-new limiter on a brand-new connection to the same file.
        $reader = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath));
        $result = $reader->attempt('shared', 3, 60);

        self::assertFalse($result['allowed'], 'the 4th attempt must be denied across connections');
        self::assertIsInt($result['retryAfter']);
    }

    #[Test]
    public function window_resets_after_expiry(): void
    {
        $now = 1_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        $limiter = new DatabaseRateLimiter(DBALDatabase::createSqlite($this->dbPath), $clock);

        // Exhaust the window of 10s.
        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('key1', 3, 10);
        }
        self::assertFalse($limiter->attempt('key1', 3, 10)['allowed']);

        // Advance past the window — a fresh window opens, attempts allowed again.
        $now = 1_011;
        $result = $limiter->attempt('key1', 3, 10);
        self::assertTrue($result['allowed']);
        self::assertSame(2, $result['remaining']);
    }
}
