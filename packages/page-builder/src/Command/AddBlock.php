<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class AddBlock implements EditCommand
{
    /** @param array<string, mixed> $block */
    public function __construct(
        public string $sectionId,
        public string $regionId,
        public int $position,
        public array $block,
    ) {}
}
