<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Execution;

use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Lease\LeaseHandle;

/** Renewable ownership and fencing context for one direct scheduled command. @api */
final class LeaseExecutionContext
{
    public function __construct(
        private readonly LeaseAuthorityInterface $authority,
        private LeaseHandle $handle,
        private readonly int $ttlMs,
        private readonly FenceGuardInterface $fenceGuard,
        private readonly string $occurrenceId = '',
    ) {}

    public function domain(): string
    {
        return $this->handle->domain;
    }

    public function fence(): int
    {
        return $this->handle->fence;
    }

    public function occurrenceId(): string
    {
        return $this->occurrenceId;
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

    /** Execute a durable effect under the current lease's sink-side fence. */
    public function effect(string $resourceKey, string $effectId, \Closure $effect): bool
    {
        $this->checkpoint();

        $scopedEffectId = $this->occurrenceId === '' ? $effectId : $this->occurrenceId . ':' . $effectId;

        return $this->fenceGuard->execute($resourceKey, $this->handle->domain, $this->handle->fence, $scopedEffectId, $effect);
    }
}
