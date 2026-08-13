<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class AddSection implements EditCommand
{
    /** @param array<string, mixed> $section */
    public function __construct(
        public int $position,
        public array $section,
    ) {}
}
