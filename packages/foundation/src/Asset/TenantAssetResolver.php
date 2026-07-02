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
 *
 * ## URL namespace <-> filesystem root pairing (audit-remediation batch 2026-07-02, WP5)
 *
 * Every entry's `baseUrl` MUST be the URL prefix that a static file server
 * would actually map back to that entry's `basePath` — i.e. `baseUrl` and
 * `basePath` are a matched pair, one URL namespace per filesystem root.
 * `entryUrlToFilePath()` (used by `url()`'s existence check) relies on this:
 * it strips `baseUrl` off a candidate URL and reattaches the remainder to
 * `basePath` to test whether the file is really there. If two entries shared
 * one `baseUrl` while pointing at two different `basePath` roots (the
 * pre-fix bug: the ssr and admin entries both used the bare `$baseUrl`
 * with no distinguishing suffix), the existence check for one entry could
 * pass against a file that a real one-root-per-URL-prefix static server
 * would never serve from that URL — because the URL doesn't encode which
 * entry it came from, only whichever entry happened to be checked first.
 *
 * This class is investigated but **not currently wired** at any composition
 * root (`waaseyaa/inertia`'s `InertiaServiceProvider` binds a bare
 * `ViteAssetManager` directly; nothing in the framework binds
 * `AssetManagerInterface` to `TenantAssetResolver`). The only static-serving
 * mechanisms in this repo — the FrankenPHP `Caddyfile` (`root ./public` +
 * `php_server`, which serves an existing file under the docroot verbatim) and
 * the `cli-server` passthrough in `public/index.php` (`is_file($file) ?
 * return false`) — both serve strictly one physical root per URL, with the
 * URL path equal to the path under that root. There is no per-bundle URL
 * rewrite or union-mount anywhere in the codebase. Given that, the only
 * internally-consistent contract for this class is: **each entry's `baseUrl`
 * must be distinct and must correspond 1:1 to its own `basePath`** — chosen
 * here as `<baseUrl>/ssr` -> `<basePath>/ssr` and `<baseUrl>/admin` ->
 * `<basePath>/admin`, mirroring the tenant-theme entry's existing
 * `<baseUrl>/themes/<theme>` -> `<basePath>/themes/<theme>/dist` pairing.
 * Should this class ever be wired to a real static-file root, that root MUST
 * serve `<baseUrl>/ssr/**` from `<basePath>/ssr/**` and `<baseUrl>/admin/**`
 * from `<basePath>/admin/**` (e.g. via reverse-proxy path rules or a docroot
 * layout that mirrors `<basePath>`) for `url()`'s existence check to remain
 * meaningful.
 *
 * @api
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

        // 2. Base SSR assets. baseUrl carries the /ssr suffix so this entry's URL
        // namespace maps 1:1 to its basePath (see class docblock) instead of
        // colliding with the admin entry's URL namespace below.
        $ssrPath = rtrim($this->basePath, '/') . '/ssr';
        $ssrUrl = rtrim($this->baseUrl, '/') . '/ssr';
        $this->entries[] = [
            'resolver' => new ViteAssetManager($ssrPath, $ssrUrl),
            'basePath' => $ssrPath,
            'baseUrl' => $ssrUrl,
        ];

        // 3. Admin SPA assets. Same reasoning: /admin suffix keeps this entry's
        // URL namespace distinct from the ssr entry's.
        $adminPath = rtrim($this->basePath, '/') . '/admin';
        $adminUrl = rtrim($this->baseUrl, '/') . '/admin';
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
