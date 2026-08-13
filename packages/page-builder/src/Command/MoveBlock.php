<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class MoveBlock implements EditCommand
{
    public function __construct(
        public string $blockId,
        public string $destinationSectionId,
        public string $destinationRegionId,
        public int $position,
    ) {}
}
