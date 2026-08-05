<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

/** @internal Optional-package anti-corruption port; not an application extension point. */
interface ContentSearchRateLimiterInterface
{
    public function consume(string $key, int $maxAttempts, int $decaySeconds): bool;
}
