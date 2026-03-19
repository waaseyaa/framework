<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

final readonly class OptionalProp
{
    public function __construct(
        public \Closure $callback,
    ) {}
}
