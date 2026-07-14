<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * @internal Parked with the first-party search read surface.
 */
final readonly class FacetBucket
{
    public function __construct(
        public string $key,
        public int $count,
    ) {}
}
