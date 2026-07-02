<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Asset\ViteAssetManager;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class InertiaServiceProvider extends ServiceProvider implements HasMiddlewareInterface
{
    public function register(): void
    {
        // Prefer the kernel-provided project root over getcwd(): the documented dev
        // server (`bin/waaseyaa serve`) launches the PHP built-in server with
        // `-t {projectRoot}/public`, which chdir()s into public/ per request, so
        // getcwd() returns the docroot and `$root . '/public'` would double-append
        // /public (manifest never found -> blank <head>). #1626 snag 1.
        $this->registerWithRoot($this->projectRoot !== '' ? $this->projectRoot : null);
    }

    /**
     * Configure the Inertia renderer with Vite asset injection.
     *
     * @param string|null $root Project root directory. Defaults to getcwd().
     */
    public function registerWithRoot(?string $root): void
    {
        $cwd = getcwd();
        if ($root === null && $cwd === false) {
            return;
        }
        $root ??= $cwd;
        $viteRaw = $_ENV['VITE_DEV_SERVER'] ?? getenv('VITE_DEV_SERVER');
        $devServerUrl = is_string($viteRaw) && $viteRaw !== '' ? $viteRaw : null;

        // Make the Vite bundle/entrypoint overridable instead of hardcoded. Null
        // preserves ViteAssetManager's current defaults ('build' / 'resources/js/app.ts'),
        // so default emitted HTML is byte-identical. #1626 snag 4.
        $bundleRaw = $_ENV['VITE_BUNDLE'] ?? getenv('VITE_BUNDLE');
        $bundle = is_string($bundleRaw) && $bundleRaw !== '' ? $bundleRaw : null;
        $entrypointRaw = $_ENV['VITE_ENTRYPOINT'] ?? getenv('VITE_ENTRYPOINT');
        $entrypoint = is_string($entrypointRaw) && $entrypointRaw !== '' ? $entrypointRaw : null;

        // Thread the kernel logger through so a missing/corrupt manifest after a
        // bad deploy leaves a signal instead of silently falling back to
        // un-hashed asset paths. Resolved optionally via the kernel-services bus
        // (bound to Waaseyaa\Foundation\Log\LoggerInterface) — absence must not
        // crash provider registration, mirroring every other optional framework
        // service pulled through resolveOptional().
        $logger = $this->resolveOptional(LoggerInterface::class);

        $assetManager = new ViteAssetManager(
            basePath: $root . '/public',
            baseUrl: '',
            devServerUrl: $devServerUrl,
            logger: $logger instanceof LoggerInterface ? $logger : null,
        );

        $renderer = new RootTemplateRenderer(
            assetManager: $assetManager,
            bundle: $bundle,
            entrypoint: $entrypoint,
        );
        Inertia::setRenderer($renderer);
        $this->singleton(InertiaFullPageRendererInterface::class, static fn(): InertiaFullPageRendererInterface => $renderer);
    }

    /** @return list<HttpMiddlewareInterface> */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [new InertiaMiddleware(Inertia::getVersion())];
    }
}
