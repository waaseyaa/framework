<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

final class ManifestBootstrapper
{
    /**
     * @param bool $freshCompile When true (dev mode), compile the manifest fresh
     *        on every boot — a full PSR-4 directory scan that discovers newly
     *        added app entity types and access policies WITHOUT
     *        `composer dump-autoload -o` or `optimize:manifest`. `compile()`
     *        already reads the root project's `extra.waaseyaa.providers` and does
     *        NOT write the cache file, so the production manifest cache is never
     *        clobbered by a dev boot. When false (production), use the compiled
     *        cache (`load()`).
     */
    public function boot(string $projectRoot, bool $freshCompile = false): PackageManifest
    {
        $compiler = new PackageManifestCompiler(
            basePath: $projectRoot,
            storagePath: $projectRoot . '/storage',
        );

        return $freshCompile ? $compiler->compile() : $compiler->load();
    }
}
