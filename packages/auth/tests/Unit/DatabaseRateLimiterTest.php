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
    private DBALDatabase $db;

    private DatabaseRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->limiter = new DatabaseRateLimiter($this->db);
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

    #[Test]
    public function counts_every_hit_exactly(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->limiter->hit('exact:key', 60);
        }

        $this->assertSame(25, $this->limiter->attempts('exact:key'));
    }

    #[Test]
    public function consume_records_and_decides_the_attempt_atomically(): void
    {
        self::assertTrue($this->limiter->consume('mcp:write:7', 2, 60));
        self::assertTrue($this->limiter->consume('mcp:write:7', 2, 60));
        self::assertFalse($this->limiter->consume('mcp:write:7', 2, 60));
        self::assertSame(3, $this->limiter->attempts('mcp:write:7'));
    }

    #[Test]
    public function consume_shares_one_durable_bucket_across_instances(): void
    {
        $other = new DatabaseRateLimiter($this->db);

        self::assertTrue($this->limiter->consume('mcp:write:7', 1, 60));
        self::assertFalse($other->consume('mcp:write:7', 1, 60));
    }

    #[Test]
    public function consume_starts_a_fresh_window_after_expiry(): void
    {
        self::assertTrue($this->limiter->consume('mcp:write:7', 1, 60));
        $this->db->update('rate_limits')->fields(['reset_at' => 0])
            ->condition('bucket_key', 'mcp:write:7')->execute();

        self::assertTrue($this->limiter->consume('mcp:write:7', 1, 60));
        self::assertSame(1, $this->limiter->attempts('mcp:write:7'));
    }

    #[Test]
    public function schema_uses_no_reserved_word_columns(): void
    {
        $this->limiter->hit('k', 60); // triggers ensureTable()

        $columns = [];
        foreach ($this->db->query('PRAGMA table_info(rate_limits)') as $row) {
            $columns[] = $row['name'];
        }

        sort($columns);
        $this->assertSame(['bucket_key', 'hits', 'reset_at'], $columns);
        $this->assertNotContains('key', $columns, 'reserved word "key" must not be a column');
        $this->assertNotContains('count', $columns, 'reserved word "count" must not be a column');
    }
}
