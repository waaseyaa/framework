<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Outcome of one canonical combined-source rebuild acceptance.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final readonly class AdminDistAcceptanceResult
{
    /**
     * @param list<string> $added
     * @param list<string> $modified
     * @param list<string> $removed obsolete generated paths proven gone from the published tree
     */
    public function __construct(
        public AdminDistAcceptanceManifest $manifest,
        public AdminDistTreeInventory $published,
        public array $added,
        public array $modified,
        public array $removed,
        public bool $publishedTreeChanged,
        public bool $manifestRewritten,
    ) {}
}
