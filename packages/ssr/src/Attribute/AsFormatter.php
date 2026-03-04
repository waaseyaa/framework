<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFormatter
{
    public function __construct(
        public readonly string $fieldType,
    ) {}
}
