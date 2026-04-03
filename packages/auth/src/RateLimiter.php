<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

final class RateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{count: int, resetAt: int}> */
    private array $attempts = [];

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
