<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Asset;

/**
 * Resolves asset URLs considering tenant-specific overrides.
 *
 * Resolution order:
 *   1. themes/{tenant-theme}/dist/  (tenant-specific)
 *   2. dist/ssr/                    (base SSR)
 *   3. dist/admin/                  (admin SPA)
 *
 * Falls back through the chain until the asset is found.
 */
final class TenantAssetResolver implements AssetManagerInterface
{
    /**
     * @var array<int, array{resolver: ViteAssetManager, basePath: string, baseUrl: string}>
     *   Resolvers in priority order (first wins), each with its filesystem root.
     */
    private array $entries = [];

    /**
     * @param string $basePath Base path to the dist directory
     * @param string $baseUrl  Base URL prefix for asset URLs
     * @param string|null $tenantTheme Tenant theme name (e.g., 'agency'). Null = no tenant override.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = '/dist',
        private readonly ?string $tenantTheme = null,
    ) {
        $this->buildResolverChain();
    }

    public function url(string $path, string $bundle = 'admin'): string
    {
        // Try each resolver in priority order.
        foreach ($this->entries as $entry) {
            $url = $entry['resolver']->url($path, $bundle);
            $filePath = $this->entryUrlToFilePath($entry, $url);
            if ($filePath !== null && file_exists($filePath)) {
                return $url;
            }
        }

        // If no file found, use the primary resolver (first in chain).
        if ($this->entries !== []) {
            return $this->entries[0]['resolver']->url($path, $bundle);
        }

        // Absolute fallback.
        return rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($path, '/');
    }

    public function preloadLinks(string $bundle = 'admin'): array
    {
        // Aggregate preload links from the primary resolver only.
        if ($this->entries !== []) {
            return $this->entries[0]['resolver']->preloadLinks($bundle);
        }

        return [];
    }

    /**
     * Get the ordered list of asset resolvers.
     *
     * @return AssetManagerInterface[]
     */
    public function getResolvers(): array
    {
        return array_map(
            fn(array $entry): AssetManagerInterface => $entry['resolver'],
            $this->entries,
        );
    }

    private function buildResolverChain(): void
    {
        // 1. Tenant theme override (if configured).
        if ($this->tenantTheme !== null) {
            $themePath = rtrim($this->basePath, '/') . '/themes/' . $this->tenantTheme . '/dist';
            $themeUrl = rtrim($this->baseUrl, '/') . '/themes/' . $this->tenantTheme;
            $this->entries[] = [
                'resolver' => new ViteAssetManager($themePath, $themeUrl),
                'basePath' => $themePath,
                'baseUrl' => $themeUrl,
            ];
        }

        // 2. Base SSR assets.
        $ssrPath = rtrim($this->basePath, '/') . '/ssr';
        $ssrUrl = rtrim($this->baseUrl, '/');
        $this->entries[] = [
            'resolver' => new ViteAssetManager($ssrPath, $ssrUrl),
            'basePath' => $ssrPath,
            'baseUrl' => $ssrUrl,
        ];

        // 3. Admin SPA assets.
        $adminPath = rtrim($this->basePath, '/') . '/admin';
        $adminUrl = rtrim($this->baseUrl, '/');
        $this->entries[] = [
            'resolver' => new ViteAssetManager($adminPath, $adminUrl),
            'basePath' => $adminPath,
            'baseUrl' => $adminUrl,
        ];
    }

    /**
     * Map a URL back to a filesystem path using the entry's base path/URL pair.
     *
     * @param array{resolver: ViteAssetManager, basePath: string, baseUrl: string} $entry
     */
    private function entryUrlToFilePath(array $entry, string $url): ?string
    {
        $baseUrl = rtrim($entry['baseUrl'], '/');
        if (str_starts_with($url, $baseUrl)) {
            $relative = substr($url, strlen($baseUrl));
            return rtrim($entry['basePath'], '/') . $relative;
        }

        return null;
    }
}
