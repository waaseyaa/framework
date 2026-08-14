<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Testing;

use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Lease\LeaseHandle;
use Waaseyaa\Scheduler\Lease\LeaseLostException;

/** Deterministic process-local lease authority for tests only. */
final class InMemoryLeaseAuthority implements LeaseAuthorityInterface
{
    /** @var array<string, LeaseHandle> */
    private array $handles = [];
    private int $nextFence = 1;

    public function acquire(string $domain, int $ttlMs): ?LeaseHandle
    {
        if (isset($this->handles[$domain])) {
            return null;
        }
        return $this->handles[$domain] = new LeaseHandle($domain, bin2hex(random_bytes(8)), $this->nextFence++, 1, bin2hex(random_bytes(8)), 1_000_000 + $ttlMs);
    }

    public function renew(LeaseHandle $handle, int $ttlMs): LeaseHandle
    {
        if (($this->handles[$handle->domain] ?? null) !== $handle) {
            throw new LeaseLostException('Lease lost.');
        }
        return $this->handles[$handle->domain] = new LeaseHandle($handle->domain, $handle->ownerToken, $handle->fence, $handle->renewalGeneration + 1, bin2hex(random_bytes(8)), $handle->expiresAtMs + $ttlMs);
    }

    public function release(LeaseHandle $handle): void
    {
        if (($this->handles[$handle->domain] ?? null) === $handle) {
            unset($this->handles[$handle->domain]);
        }
    }
}
