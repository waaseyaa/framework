<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\RateLimit;

interface RateLimiterInterface
{
    /**
     * @return array{allowed: bool, remaining: int, retryAfter: ?int}
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array;
}
