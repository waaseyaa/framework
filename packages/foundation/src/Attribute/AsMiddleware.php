<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsMiddleware
{
    public function __construct(
        public readonly string $pipeline,
        public readonly int $priority = 0,
    ) {}
}
