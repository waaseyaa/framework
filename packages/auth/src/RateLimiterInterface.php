<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

/** @internal */
interface RateLimiterInterface
{
    /**
     * Record a hit for the given key.
     */
    public function hit(string $key, int $decaySeconds): void;

    /**
     * Check if the key has exceeded the max attempts.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool;

    /**
     * Get the number of attempts for the key.
     */
    public function attempts(string $key): int;

    /**
     * Get the remaining attempts before hitting the limit.
     */
    public function remaining(string $key, int $maxAttempts): int;

    /**
     * Clear attempts for the given key.
     */
    public function clear(string $key): void;
}
