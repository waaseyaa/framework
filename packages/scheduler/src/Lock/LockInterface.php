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
     * @param int $ttl Time-to-live in seconds
     */
    public function acquire(string $name, int $ttl = 300): bool;

    public function release(string $name): void;
}
