<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lock;

final class InMemoryLock implements LockInterface
{
    /** @var array<string, int> name => expires_at timestamp */
    private array $locks = [];

    public function acquire(string $name, int $ttl = 300): bool
    {
        $now = time();

        if (isset($this->locks[$name]) && $this->locks[$name] > $now) {
            return false;
        }

        $this->locks[$name] = $now + $ttl;

        return true;
    }

    public function release(string $name): void
    {
        unset($this->locks[$name]);
    }
}
