<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\RateLimit;

final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{count: int, windowStart: int}> */
    private array $windows = [];

    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();

        if (isset($this->windows[$key])) {
            $window = $this->windows[$key];
            $windowEnd = $window['windowStart'] + $windowSeconds;

            if ($now >= $windowEnd) {
                // Window expired — start a new one.
                $this->windows[$key] = ['count' => 1, 'windowStart' => $now];

                return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retryAfter' => null];
            }

            // Window still active.
            $this->windows[$key]['count']++;
            $count = $this->windows[$key]['count'];

            if ($count > $maxAttempts) {
                $retryAfter = $windowEnd - $now;

                return ['allowed' => false, 'remaining' => 0, 'retryAfter' => $retryAfter];
            }

            return ['allowed' => true, 'remaining' => $maxAttempts - $count, 'retryAfter' => null];
        }

        // First request for this key.
        $this->windows[$key] = ['count' => 1, 'windowStart' => $now];

        return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retryAfter' => null];
    }
}
