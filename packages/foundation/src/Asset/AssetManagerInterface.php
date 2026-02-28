<?php

declare(strict_types=1);

namespace Aurora\Foundation\Asset;

/**
 * Resolves source asset paths to versioned/hashed production URLs.
 *
 * Implementations read build manifests (e.g., Vite manifest.json)
 * to map source file paths to their production-hashed equivalents.
 */
interface AssetManagerInterface
{
    /**
     * Resolve a source asset path to its production URL.
     *
     * @param string $path   Source file path (e.g., 'css/main.css', 'js/app.ts')
     * @param string $bundle Asset bundle name (e.g., 'admin', 'ssr', 'theme-agency')
     *
     * @return string Resolved URL with content hash for cache busting
     */
    public function url(string $path, string $bundle = 'admin'): string;

    /**
     * Get preload link entries for a bundle's critical assets.
     *
     * Returns an array of associative arrays with 'href' and 'as' keys
     * suitable for generating <link rel="modulepreload"> tags.
     *
     * @param string $bundle Asset bundle name
     *
     * @return array<int, array{href: string, as: string}>
     */
    public function preloadLinks(string $bundle = 'admin'): array;
}
