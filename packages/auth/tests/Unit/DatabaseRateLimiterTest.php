<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(DatabaseRateLimiter::class)]
final class DatabaseRateLimiterTest extends TestCase
{
    private DatabaseRateLimiter $limiter;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite();
        $this->limiter = new DatabaseRateLimiter($db);
    }

    #[Test]
    public function allows_attempts_within_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->limiter->tooManyAttempts('login:alice', 5));
            $this->limiter->hit('login:alice', 60);
        }
    }

    #[Test]
    public function blocks_after_max_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('login:alice', 60);
        }

        $this->assertTrue($this->limiter->tooManyAttempts('login:alice', 5));
    }

    #[Test]
    public function clear_resets_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('login:alice', 60);
        }

        $this->limiter->clear('login:alice');

        $this->assertFalse($this->limiter->tooManyAttempts('login:alice', 5));
    }

    #[Test]
    public function different_keys_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('login:alice', 60);
        }

        $this->assertTrue($this->limiter->tooManyAttempts('login:alice', 5));
        $this->assertFalse($this->limiter->tooManyAttempts('login:bob', 5));
    }

    #[Test]
    public function attempts_returns_count(): void
    {
        $this->limiter->hit('login:alice', 60);
        $this->limiter->hit('login:alice', 60);
        $this->limiter->hit('login:alice', 60);

        $this->assertSame(3, $this->limiter->attempts('login:alice'));
    }

    #[Test]
    public function attempts_returns_zero_for_unknown_key(): void
    {
        $this->assertSame(0, $this->limiter->attempts('unknown'));
    }

    #[Test]
    public function remaining_returns_correct_count(): void
    {
        $this->limiter->hit('login:alice', 60);
        $this->limiter->hit('login:alice', 60);

        $this->assertSame(3, $this->limiter->remaining('login:alice', 5));
    }

    #[Test]
    public function persists_across_instances_with_same_database(): void
    {
        $db = DBALDatabase::createSqlite();
        $limiter1 = new DatabaseRateLimiter($db);
        $limiter1->hit('login:alice', 60);
        $limiter1->hit('login:alice', 60);

        $limiter2 = new DatabaseRateLimiter($db);
        $this->assertSame(2, $limiter2->attempts('login:alice'));
    }
}
