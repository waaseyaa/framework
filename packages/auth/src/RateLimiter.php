<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

/**
 * In-memory rate limiter. State lives in a per-instance array, so under the
 * framework's boot-per-request runtime it resets every request and does not
 * actually throttle across requests — the live, bound implementation is
 * {@see DatabaseRateLimiter}. Retained only as a fallback for tests and
 * non-boot-per-request contexts; it is NOT part of the public surface.
 *
 * @internal
 */
final class RateLimiter implements AtomicRateLimiterInterface
{
    /** @var array<string, array{count: int, resetAt: int}> */
    private array $attempts = [];

    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $decaySeconds);

        return true;
    }

    /**
     * Record a hit for the given key.
     */
    public function hit(string $key, int $decaySeconds): void
    {
        $this->pruneExpired($key);

        if (!isset($this->attempts[$key])) {
            $this->attempts[$key] = [
                'count' => 0,
                'resetAt' => time() + $decaySeconds,
            ];
        }

        $this->attempts[$key]['count']++;
    }

    /**
     * Check if the key has exceeded the max attempts.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $this->pruneExpired($key);

        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Get the number of attempts for the key.
     */
    public function attempts(string $key): int
    {
        $this->pruneExpired($key);

        return $this->attempts[$key]['count'] ?? 0;
    }

    /**
     * Get the remaining attempts before hitting the limit.
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /**
     * Clear attempts for the given key.
     */
    public function clear(string $key): void
    {
        unset($this->attempts[$key]);
    }

    private function pruneExpired(string $key): void
    {
        if (isset($this->attempts[$key]) && $this->attempts[$key]['resetAt'] <= time()) {
            unset($this->attempts[$key]);
        }
    }
}
