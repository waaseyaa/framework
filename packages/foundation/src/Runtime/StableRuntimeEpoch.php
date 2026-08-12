<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Runtime;

/** Default epoch for compositions with no reload-sensitive durable authority. */
final class StableRuntimeEpoch implements RuntimeEpochInterface
{
    public function hasChanged(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'runtime-epoch:stable';
    }
}
