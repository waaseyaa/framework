<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

final readonly class ResolvedPath
{
    public function __construct(
        public string $alias,
        public string $systemPath,
        public string $entityTypeId,
        public int|string $entityId,
    ) {}
}
