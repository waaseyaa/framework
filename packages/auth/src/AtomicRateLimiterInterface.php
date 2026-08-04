<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

/**
 * A rate limiter that records and decides one attempt atomically.
 *
 * @api
 */
interface AtomicRateLimiterInterface extends RateLimiterInterface
{
    /**
     * Consume one attempt and return true only when it is within the limit.
     */
    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool;
}
