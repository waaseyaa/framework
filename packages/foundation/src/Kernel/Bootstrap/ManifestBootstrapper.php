<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

final class ManifestBootstrapper
{
    public function boot(string $projectRoot): PackageManifest
    {
        $compiler = new PackageManifestCompiler(
            basePath: $projectRoot,
            storagePath: $projectRoot . '/storage',
        );

        return $compiler->load();
    }
}
