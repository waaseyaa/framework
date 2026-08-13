<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class MoveSection implements EditCommand
{
    public function __construct(
        public string $sectionId,
        public int $position,
    ) {}
}
