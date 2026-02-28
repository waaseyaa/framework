<?php

declare(strict_types=1);

namespace Aurora\Queue\Attribute;

/**
 * Rate limits job execution.
 *
 * When applied to a Job subclass, queue processors should enforce
 * that the job is not attempted more than maxAttempts times within
 * the decaySeconds window.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RateLimited
{
    /**
     * @param int $maxAttempts  Maximum number of attempts within the decay window.
     * @param int $decaySeconds The time window (in seconds) for rate limiting.
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly int $decaySeconds = 60,
    ) {}
}
