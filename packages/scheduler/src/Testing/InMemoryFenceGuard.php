<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Testing;

use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Fence\StaleFenceException;

/** Process-local fence guard for deterministic tests only. */
final class InMemoryFenceGuard implements FenceGuardInterface
{
    /** @var array<string, array{fence: int, effect: string}> */
    private array $accepted = [];

    public function execute(string $resourceKey, string $fenceDomain, int $fence, string $effectId, \Closure $effect): bool
    {
        $key = $resourceKey . "\0" . $fenceDomain;
        $current = $this->accepted[$key] ?? null;
        if ($current !== null && $fence < $current['fence']) {
            throw new StaleFenceException('Stale fence.');
        }
        if ($current !== null && $fence === $current['fence']) {
            if ($effectId === $current['effect']) {
                return false;
            }
            throw new StaleFenceException('Distinct equal-fence effect.');
        }
        $sameEffectRecovery = $current !== null && $current['effect'] === $effectId;
        $this->accepted[$key] = ['fence' => $fence, 'effect' => $effectId];
        if ($sameEffectRecovery) {
            return false;
        }
        try {
            $effect();
        } catch (\Throwable $error) {
            if ($current === null) {
                unset($this->accepted[$key]);
            } else {
                $this->accepted[$key] = $current;
            }
            throw $error;
        }

        return true;
    }
}
