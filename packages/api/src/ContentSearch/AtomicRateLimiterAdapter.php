<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

final class AtomicRateLimiterAdapter implements ContentSearchRateLimiterInterface
{
    private const string CONTRACT = 'Waaseyaa\\Auth\\AtomicRateLimiterInterface';

    /** @var \Closure(string, int, int): mixed */
    private readonly \Closure $consume;

    public function __construct(object $limiter)
    {
        if (!is_a($limiter, self::CONTRACT)) {
            throw new ContentSearchBoundaryException('The optional atomic rate limiter has an incompatible contract.');
        }
        $this->consume = \Closure::fromCallable([$limiter, 'consume']);
    }

    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        // is_a() above establishes the PHP return-type contract; an invalid
        // implementation raises TypeError and is sanitized by the controller.
        return ($this->consume)($key, $maxAttempts, $decaySeconds);
    }
}
