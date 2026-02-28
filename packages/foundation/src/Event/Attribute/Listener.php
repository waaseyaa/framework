<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Listener
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
