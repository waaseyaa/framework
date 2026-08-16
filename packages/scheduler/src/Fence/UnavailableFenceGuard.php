<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Fence;

final class UnavailableFenceGuard implements FenceGuardInterface
{
    public function execute(string $resourceKey, string $fenceDomain, int $fence, string $effectId, \Closure $effect): bool
    {
        throw new \RuntimeException('Fenced scheduled effects require the durable database fence authority.');
    }
}
