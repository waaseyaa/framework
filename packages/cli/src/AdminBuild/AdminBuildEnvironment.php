<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final readonly class AdminBuildEnvironment
{
    /** @param array<string, string> $variables */
    public function __construct(
        public string $npmExecutable,
        public string $nodeExecutable,
        public array $variables,
    ) {}
}
