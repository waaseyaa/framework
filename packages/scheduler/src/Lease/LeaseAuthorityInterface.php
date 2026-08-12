<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lease;

/** @internal */
interface LeaseAuthorityInterface
{
    public function acquire(string $domain, int $ttlMs): ?LeaseHandle;

    public function renew(LeaseHandle $handle, int $ttlMs): LeaseHandle;

    public function release(LeaseHandle $handle): void;
}
