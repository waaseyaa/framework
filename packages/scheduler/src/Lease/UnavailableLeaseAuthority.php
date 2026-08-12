<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lease;

/** Fail-closed authority used when no durable database is composed. */
final class UnavailableLeaseAuthority implements LeaseAuthorityInterface
{
    public function acquire(string $domain, int $ttlMs): ?LeaseHandle
    {
        throw new \RuntimeException('Overlap-protected scheduling requires the durable database lease authority.');
    }

    public function renew(LeaseHandle $handle, int $ttlMs): LeaseHandle
    {
        throw new LeaseLostException('The durable database lease authority is unavailable.');
    }

    public function release(LeaseHandle $handle): void
    {
        throw new LeaseLostException('The durable database lease authority is unavailable.');
    }
}
