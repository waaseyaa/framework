<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\RateLimiter;

#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    public function testAllowsAttemptsWithinLimit(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($limiter->tooManyAttempts('login:alice', 5));
            $limiter->hit('login:alice', 60);
        }
    }

    public function testBlocksAfterMaxAttempts(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('login:alice', 60);
        }

        $this->assertTrue($limiter->tooManyAttempts('login:alice', 5));
    }

    public function testClearResetsAttempts(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('login:alice', 60);
        }

        $limiter->clear('login:alice');

        $this->assertFalse($limiter->tooManyAttempts('login:alice', 5));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('login:alice', 60);
        }

        $this->assertTrue($limiter->tooManyAttempts('login:alice', 5));
        $this->assertFalse($limiter->tooManyAttempts('login:bob', 5));
    }

    public function testAttemptsReturnsCount(): void
    {
        $limiter = new RateLimiter();

        $limiter->hit('login:alice', 60);
        $limiter->hit('login:alice', 60);
        $limiter->hit('login:alice', 60);

        $this->assertSame(3, $limiter->attempts('login:alice'));
    }

    public function testAttemptsReturnsZeroForUnknownKey(): void
    {
        $limiter = new RateLimiter();

        $this->assertSame(0, $limiter->attempts('unknown'));
    }

    public function testRemainingReturnsCorrectCount(): void
    {
        $limiter = new RateLimiter();

        $limiter->hit('login:alice', 60);
        $limiter->hit('login:alice', 60);

        $this->assertSame(3, $limiter->remaining('login:alice', 5));
    }
}
