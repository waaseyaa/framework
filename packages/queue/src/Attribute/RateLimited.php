<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Attribute;

/**
 * Applies a rate limit to job dispatch within a single process.
 *
 * **IMPORTANT — in-process / SyncQueue-only. NOT enforced by DbalQueue.**
 *
 * This attribute is evaluated by {@see \Waaseyaa\Queue\AttributeGuard}, which
 * performs pure in-memory, per-process tracking. It is enforced by
 * {@see \Waaseyaa\Queue\SyncQueue} (same-process execution) and has NO effect
 * when jobs are dispatched through {@see \Waaseyaa\Queue\DbalQueue} (the
 * persistent, transport-backed driver). Cross-process rate-limiting would
 * require a distributed counter/bucket store and is currently unimplemented.
 *
 * Using this attribute with DbalQueue will cause a warning to be logged at
 * dispatch time so that the no-op is non-silent.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RateLimited
{
    /**
     * @param int $maxAttempts  Maximum number of attempts within the decay window (in-process only).
     * @param int $decaySeconds The time window (in seconds) for rate limiting.
     */
    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly int $decaySeconds = 60,
    ) {}
}
