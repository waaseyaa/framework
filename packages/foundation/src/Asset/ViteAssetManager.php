<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Asset;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Asset manager that reads Vite manifest.json files to resolve asset URLs.
 *
 * Each bundle corresponds to a Vite build output directory containing a
 * manifest.json file. The manifest maps source paths to their hashed outputs.
 *
 * Expected manifest format (Vite 5+):
 * {
 *   "src/main.ts": {
 *     "file": "assets/main-abc123.js",
 *     "css": ["assets/main-def456.css"],
 *     "isEntry": true,
 *     "imports": ["_vendor-ghi789.js"]
 *   }
 * }
 *
 * ## Fail-open observability
 *
 * `loadManifest()` fails open: a missing/unreadable/corrupt manifest resolves
 * to an empty array (rather than throwing), so `url()` falls back to
 * un-hashed paths instead of crashing the request. That silence is dangerous
 * after a bad deploy (truncated or absent manifest = sitewide asset 404s with
 * zero signal), so every failure is logged once per bundle via the injected
 * `LoggerInterface` (the memoization in `$manifests` guarantees `loadManifest()`
 * only computes — and therefore only logs — once per bundle for the life of
 * the instance).
 *
 * Logging rule: a `missing` manifest is logged at ERROR, *except* when
 * `$devServerUrl` is set, in which case it is downgraded to DEBUG — a missing
 * production manifest is the normal, expected state in dev mode (see
 * `assetTags()`, which falls through to `devTags()`). `unreadable`,
 * `corrupt-json`, and `non-array` failures are always logged at ERROR
 * regardless of dev-server configuration, because those indicate a manifest
 * that exists but is broken, which is never expected in any mode.
 */
final class ViteAssetManager implements AssetManagerInterface
{
    /** @var array<string, array<string, mixed>> Cached parsed manifests per bundle */
    private array $manifests = [];

    private readonly LoggerInterface $logger;

    /**
     * @param string $basePath Base path to the dist directory (e.g., '/var/www/dist')
     * @param string $baseUrl  Base URL prefix for asset URLs (e.g., '/dist' or 'https://cdn.example.com/dist')
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = '/dist',
        private readonly ?string $devServerUrl = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function url(string $path, string $bundle = 'admin'): string
    {
        $manifest = $this->loadManifest($bundle);

        if (!isset($manifest[$path])) {
            // Fall back to the raw path if not found in manifest.
            return rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($path, '/');
        }

        $entry = $manifest[$path];
        $file = $entry['file'] ?? $path;

        return rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($file, '/');
    }

    public function preloadLinks(string $bundle = 'admin'): array
    {
        $manifest = $this->loadManifest($bundle);
        $links = [];

        foreach ($manifest as $entry) {
            if (!isset($entry['isEntry']) || $entry['isEntry'] !== true) {
                continue;
            }

            // Add the entry file itself.
            if (isset($entry['file'])) {
                $links[] = [
                    'href' => rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($entry['file'], '/'),
                    'as' => $this->guessAssetType($entry['file']),
                ];
            }

            // Add CSS files associated with this entry.
            if (isset($entry['css']) && is_array($entry['css'])) {
                foreach ($entry['css'] as $cssFile) {
                    $links[] = [
                        'href' => rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($cssFile, '/'),
                        'as' => 'style',
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * Generate HTML script and link tags for a bundle's entry assets.
     *
     * In production (manifest exists): emits hashed asset tags.
     * In dev mode (no manifest, devServerUrl set): emits Vite dev server tags.
     * Otherwise: returns empty string.
     */
    public function assetTags(string $bundle = 'build', string $entrypoint = 'resources/js/app.ts'): string
    {
        $manifest = $this->loadManifest($bundle);

        if ($manifest !== []) {
            return $this->productionTags($manifest, $bundle);
        }

        if ($this->devServerUrl !== null) {
            return $this->devTags($entrypoint);
        }

        return '';
    }

    private function productionTags(array $manifest, string $bundle): string
    {
        $tags = [];

        foreach ($manifest as $entry) {
            if (!isset($entry['isEntry']) || $entry['isEntry'] !== true) {
                continue;
            }

            if (isset($entry['css']) && is_array($entry['css'])) {
                foreach ($entry['css'] as $cssFile) {
                    $href = rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($cssFile, '/');
                    $tags[] = '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                }
            }

            if (isset($entry['file'])) {
                $src = rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($entry['file'], '/');
                $tags[] = '<script type="module" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
            }
        }

        return implode("\n        ", $tags);
    }

    private function devTags(string $entrypoint): string
    {
        $base = rtrim($this->devServerUrl, '/');

        return '<script type="module" src="' . htmlspecialchars($base . '/@vite/client', ENT_QUOTES, 'UTF-8') . '"></script>'
            . "\n        "
            . '<script type="module" src="' . htmlspecialchars($base . '/' . ltrim($entrypoint, '/'), ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(string $bundle): array
    {
        if (isset($this->manifests[$bundle])) {
            return $this->manifests[$bundle];
        }

        $viteManifestPath = rtrim($this->basePath, '/') . '/' . $bundle . '/.vite/manifest.json';
        // Legacy location without .vite prefix.
        $legacyManifestPath = rtrim($this->basePath, '/') . '/' . $bundle . '/manifest.json';

        $manifestPath = $viteManifestPath;
        if (!file_exists($manifestPath)) {
            $manifestPath = $legacyManifestPath;
        }

        if (!file_exists($manifestPath)) {
            $this->logManifestFailure('missing', $bundle, [$viteManifestPath, $legacyManifestPath]);
            $this->manifests[$bundle] = [];
            return [];
        }

        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            $this->logManifestFailure('unreadable', $bundle, [$manifestPath]);
            $this->manifests[$bundle] = [];
            return [];
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logManifestFailure('corrupt-json', $bundle, [$manifestPath]);
            $this->manifests[$bundle] = [];
            return [];
        }
        if (!is_array($manifest)) {
            $this->logManifestFailure('non-array', $bundle, [$manifestPath]);
            $this->manifests[$bundle] = [];
            return [];
        }

        $this->manifests[$bundle] = $manifest;

        return $manifest;
    }

    /**
     * Log a manifest-load failure. Called at most once per bundle — the
     * `$manifests` memoization in {@see loadManifest()} short-circuits every
     * subsequent call for the same bundle before this is reached.
     *
     * `missing` is downgraded to DEBUG when a dev server is configured (the
     * expected dev-mode state: `assetTags()` falls through to dev-server
     * tags). Every other failure kind — the manifest file exists but could
     * not be read or parsed — is always ERROR.
     *
     * @param 'missing'|'unreadable'|'corrupt-json'|'non-array' $kind
     * @param list<string> $probedPaths
     */
    private function logManifestFailure(string $kind, string $bundle, array $probedPaths): void
    {
        $level = ($kind === 'missing' && $this->devServerUrl !== null) ? LogLevel::DEBUG : LogLevel::ERROR;

        $this->logger->log($level, 'Vite manifest load failed', [
            'kind' => $kind,
            'bundle' => $bundle,
            'probed_paths' => $probedPaths,
        ]);
    }

    private function guessAssetType(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return match ($extension) {
            'js', 'mjs', 'ts' => 'script',
            'css' => 'style',
            'woff', 'woff2', 'ttf', 'otf', 'eot' => 'font',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif' => 'image',
            default => 'script',
        };
    }
}
