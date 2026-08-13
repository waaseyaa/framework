<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class DuplicateBlock implements EditCommand
{
    public function __construct(
        public string $sourceBlockId,
        public string $duplicateBlockId,
    ) {}
}
