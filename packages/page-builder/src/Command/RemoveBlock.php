<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class RemoveBlock implements EditCommand
{
    public function __construct(public string $blockId) {}
}
