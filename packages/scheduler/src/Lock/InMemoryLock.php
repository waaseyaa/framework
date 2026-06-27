<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lock;

final class InMemoryLock implements LockInterface
{
    /** @var array<string, array{expires_at: int, token: string}> */
    private array $locks = [];

    public function acquire(string $name, int $ttl = 300): ?string
    {
        $now = time();

        if (isset($this->locks[$name]) && $this->locks[$name]['expires_at'] > $now) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->locks[$name] = ['expires_at' => $now + $ttl, 'token' => $token];

        return $token;
    }

    public function release(string $name, string $token): void
    {
        // Ownership-scoped (parity with DatabaseLock): only the current owner can
        // release. A stale holder whose lease was reclaimed (token rotated by a
        // later acquire) releases nothing — scheduler m15.
        if (isset($this->locks[$name]) && hash_equals($this->locks[$name]['token'], $token)) {
            unset($this->locks[$name]);
        }
    }
}
