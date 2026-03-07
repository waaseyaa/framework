<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class FacetBucket
{
    public function __construct(
        public string $key,
        public int $count,
    ) {}
}
