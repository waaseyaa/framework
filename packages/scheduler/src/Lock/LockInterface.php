<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lock;

/**
 * @internal
 */
interface LockInterface
{
    /**
     * Attempt to acquire a named lock.
     *
     * On success returns a random OWNER TOKEN that the caller must hand back to
     * {@see release()} so only the owner can release. On failure (lock already
     * held by a live holder) returns null. This ownership scoping closes the
     * split-brain double-run window (scheduler m15): under task overrun a lease
     * can expire mid-run and be reclaimed by another node; an un-scoped release
     * would then delete the new owner's live lock.
     *
     * @param int $ttl Time-to-live in seconds — must exceed the task's runtime.
     * @return string|null Owner token on success, null if the lock is held.
     */
    public function acquire(string $name, int $ttl = 300): ?string;

    /**
     * Release a lock, but only if $token matches the current owner. A stale
     * holder whose lease was already reclaimed by another node deletes nothing.
     */
    public function release(string $name, string $token): void;
}
