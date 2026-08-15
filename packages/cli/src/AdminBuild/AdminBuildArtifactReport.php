<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final readonly class AdminBuildArtifactReport
{
    /** @param list<string> $files */
    public function __construct(
        public array $files,
        public string $inventoryHash,
        public bool $clean,
    ) {}
}
