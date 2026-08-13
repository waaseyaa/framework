<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Command;

/** @api */
final readonly class ConfigureBlock implements EditCommand
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public string $blockId,
        public array $config,
    ) {}
}
