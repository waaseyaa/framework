<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class DuplicateSection implements EditCommand
{
    /** @param array<string, string> $duplicateBlockIds */
    public function __construct(
        public string $sourceSectionId,
        public string $duplicateSectionId,
        public array $duplicateBlockIds,
    ) {}
}
