<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Queue\Attribute\RateLimited;
use Waaseyaa\Queue\Attribute\UniqueJob;

/**
 * Enforces #[UniqueJob] and #[RateLimited] attributes on job messages.
 *
 * **IMPORTANT — in-process / SyncQueue-only. NOT enforced by DbalQueue.**
 *
 * This guard uses pure in-memory tracking (instance arrays, no external
 * dependencies). It is called by {@see \Waaseyaa\Queue\SyncQueue} and
 * enforces both attributes correctly within a single PHP process. It is
 * NOT called by {@see \Waaseyaa\Queue\DbalQueue} (the persistent,
 * transport-backed driver) — cross-process enforcement would require a
 * distributed dedup/rate-limit store and is currently unimplemented.
 *
 * - UniqueJob: the first dispatch of a job acquires a lock keyed on the job
 *   class name (or the custom key). Any subsequent dispatch within the
 *   lockSeconds window is skipped.
 *
 * - RateLimited: counts dispatches within the decaySeconds window and skips
 *   any dispatch that would exceed maxAttempts.
 *
 * @api
 */
final class AttributeGuard
{
    /**
     * Unique-job locks.
     *
     * Map of lock-key => expiry timestamp (float, from microtime(true)).
     *
     * @var array<string, float>
     */
    private array $uniqueLocks = [];

    /**
     * Rate-limit buckets.
     *
     * Map of rate-key => list of attempt timestamps (float, from microtime(true)).
     *
     * @var array<string, list<float>>
     */
    private array $rateBuckets = [];

    /**
     * Determine whether the given message is allowed to be handled.
     *
     * Returns true when the message may proceed; false when it should be
     * skipped because a uniqueness lock is active or the rate limit is
     * exceeded.
     */
    public function allows(object $message): bool
    {
        $reflection = new \ReflectionClass($message);
        $now = microtime(true);

        // --- #[UniqueJob] ---
        $uniqueAttrs = $reflection->getAttributes(UniqueJob::class);
        if ($uniqueAttrs !== []) {
            /** @var UniqueJob $attr */
            $attr = $uniqueAttrs[0]->newInstance();
            $lockKey = $attr->key !== '' ? $attr->key : $reflection->getName();

            if (isset($this->uniqueLocks[$lockKey]) && $this->uniqueLocks[$lockKey] > $now) {
                // Lock is still active — skip this dispatch.
                return false;
            }

            // Acquire the lock (set or refresh it).
            $this->uniqueLocks[$lockKey] = $now + $attr->lockSeconds;
        }

        // --- #[RateLimited] ---
        $rateAttrs = $reflection->getAttributes(RateLimited::class);
        if ($rateAttrs !== []) {
            /** @var RateLimited $attr */
            $attr = $rateAttrs[0]->newInstance();
            $rateKey = $reflection->getName();
            $windowStart = $now - $attr->decaySeconds;

            // Prune attempts that have fallen outside the current window.
            $this->rateBuckets[$rateKey] = array_values(
                array_filter(
                    $this->rateBuckets[$rateKey] ?? [],
                    static fn(float $ts): bool => $ts > $windowStart,
                ),
            );

            if (count($this->rateBuckets[$rateKey]) >= $attr->maxAttempts) {
                // Rate limit exceeded — skip this dispatch.
                return false;
            }

            // Record this attempt.
            $this->rateBuckets[$rateKey][] = $now;
        }

        return true;
    }

    /**
     * Release the unique-job lock for the given key.
     *
     * Useful in tests or when a job completes and the lock should be
     * freed earlier than its natural expiry.
     */
    public function releaseLock(string $key): void
    {
        unset($this->uniqueLocks[$key]);
    }

    /**
     * Reset all in-memory state.
     *
     * Intended for use in tests.
     */
    public function reset(): void
    {
        $this->uniqueLocks = [];
        $this->rateBuckets = [];
    }
}
