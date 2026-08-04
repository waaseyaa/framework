<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Auth\AtomicRateLimiterInterface;

/**
 * Fail-closed limiter used when an enabled MCP tier has no durable store.
 *
 * MCP calls the atomic consume operation, while substitutability requires this
 * adapter to retain every method on the public AtomicRateLimiterInterface even
 * when a particular method has no in-repository caller.
 *
 * @api
 */
final class UnavailableRateLimiter implements AtomicRateLimiterInterface
{
    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }

    public function hit(string $key, int $decaySeconds): void
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }

    public function attempts(string $key): int
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }

    public function clear(string $key): void
    {
        throw new \RuntimeException('The durable MCP rate limiter is unavailable.');
    }
}
