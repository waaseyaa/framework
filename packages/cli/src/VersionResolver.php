<?php

declare(strict_types=1);

namespace Waaseyaa\CLI;

final readonly class VersionResolver
{
    public function __construct(private string $projectRoot) {}

    public function resolve(): string
    {
        $versionFile = $this->projectRoot . '/VERSION';
        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        return 'Waaseyaa CLI';
    }
}
