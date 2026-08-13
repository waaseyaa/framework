<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Definition;

/** @api */
final readonly class LayoutDefinition
{
    /**
     * @param list<string> $regions
     * @param list<string> $requiredRegions
     * @param list<string> $allowedBlocks
     */
    public function __construct(
        public string $id,
        public int $version,
        public array $regions,
        public array $requiredRegions,
        public array $allowedBlocks,
    ) {
        DefinitionIdentity::assert($id, $version, 'layout');
        DefinitionIdentity::assertUniqueIds($regions, 'layout region');
        DefinitionIdentity::assertUniqueIds($allowedBlocks, 'allowed block');
        DefinitionIdentity::assertUniqueIds($requiredRegions, 'required region');
        foreach ($requiredRegions as $region) {
            if (!in_array($region, $regions, true)) {
                throw new \InvalidArgumentException("Required region is not declared by layout: {$region}");
            }
        }
    }
}
