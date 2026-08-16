<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Fence;

/** @internal */
interface FenceGuardInterface
{
    /**
     * Execute one durable effect under a resource-local fence.
     *
     * @param \Closure(): void $effect
     * @return bool True when executed; false for an exact idempotent replay.
     */
    public function execute(string $resourceKey, string $fenceDomain, int $fence, string $effectId, \Closure $effect): bool;
}
