<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Execution;

use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Lease\LeaseHandle;

/** Renewable ownership and fencing context for one direct scheduled command. */
final class LeaseExecutionContext
{
    public function __construct(
        private readonly LeaseAuthorityInterface $authority,
        private LeaseHandle $handle,
        private readonly int $ttlMs,
    ) {}

    public function domain(): string
    {
        return $this->handle->domain;
    }

    public function fence(): int
    {
        return $this->handle->fence;
    }

    /** Renew before the next durable effect; failure aborts the command. */
    public function checkpoint(): void
    {
        $this->handle = $this->authority->renew($this->handle, $this->ttlMs);
    }

    public function release(): void
    {
        $this->authority->release($this->handle);
    }
}
