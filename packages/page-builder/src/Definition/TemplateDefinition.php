<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Definition;

/** @api */
final readonly class TemplateDefinition
{
    /**
     * @param list<string> $allowedLayouts
     * @param list<string> $allowedBlocks
     */
    public function __construct(
        public string $id,
        public int $version,
        public array $allowedLayouts,
        public array $allowedBlocks,
    ) {
        DefinitionIdentity::assert($id, $version, 'template');
        DefinitionIdentity::assertUniqueIds($allowedLayouts, 'allowed layout');
        DefinitionIdentity::assertUniqueIds($allowedBlocks, 'allowed block');
    }
}
