<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\RateLimit\InMemoryRateLimiter;

#[CoversClass(InMemoryRateLimiter::class)]
final class InMemoryRateLimiterTest extends TestCase
{
    #[Test]
    public function allows_first_attempt(): void
    {
        $limiter = new InMemoryRateLimiter();

        $result = $limiter->attempt('key1', 5, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(4, $result['remaining']);
        $this->assertNull($result['retryAfter']);
    }

    #[Test]
    public function tracks_remaining_attempts(): void
    {
        $limiter = new InMemoryRateLimiter();

        $limiter->attempt('key1', 3, 60);
        $limiter->attempt('key1', 3, 60);
        $result = $limiter->attempt('key1', 3, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    #[Test]
    public function denies_after_limit_exceeded(): void
    {
        $limiter = new InMemoryRateLimiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('key1', 3, 60);
        }

        $result = $limiter->attempt('key1', 3, 60);

        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertIsInt($result['retryAfter']);
        $this->assertGreaterThan(0, $result['retryAfter']);
    }

    #[Test]
    public function isolates_keys(): void
    {
        $limiter = new InMemoryRateLimiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('key1', 3, 60);
        }

        $result = $limiter->attempt('key2', 3, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(2, $result['remaining']);
    }
}
