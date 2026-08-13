<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class ChangeSectionLayout implements EditCommand
{
    public function __construct(
        public string $sectionId,
        public string $layoutId,
        public int $layoutVersion,
    ) {}
}
