<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * Resolves package-declared migrations from `extra.waaseyaa.migrations`.
 *
 * **Manifest entry shapes (per spec §15 Q9 / WP11):**
 *
 * - String — single legacy directory path:
 *   `"migrations": "migrations"`
 * - Ordered list of mixed kinds — paths and FQCN namespace roots:
 *   `"migrations": ["Vendor\\Pkg\\Migrations\\v2", "../patches/v2"]`
 *
 * The string form continues to work indefinitely (Q9 — no removal
 * date). Internally it is normalised to `[string]` so both shapes
 * traverse the same code path.
 *
 * **Per-entry classification (heuristic, locked by ADR 009):**
 *
 * - Contains a backslash (`\`) — treated as a PHP FQCN namespace
 *   prefix. Composer's classmap is consulted; every class under that
 *   prefix that implements {@see MigrationInterfaceV2} is loaded.
 * - Otherwise — treated as a path string and resolved as a directory
 *   relative to the package install path. Existing legacy `*.php`
 *   files in lex order are loaded.
 *
 * **Discovery requirement:** v2 namespace discovery uses Composer's
 * classmap. Run `composer dump-autoload --optimize` for reliable
 * classmap-only installs; non-optimized installs may not surface
 * every class under PSR-4 prefixes. Documented in ADR 009.
 *
 * **Two methods, two return shapes:**
 *
 * - {@see loadAll()} — legacy migrations keyed by package then name.
 *   Backwards-compatible with pre-WP11 callers (e.g. existing kernel
 *   wiring). Skips FQCN entries.
 * - {@see loadAllV2()} — flat list of v2 migration instances. Skips
 *   path entries. Preserves manifest order across packages and
 *   across entries within a package.
 *
 * @api
 */
final class MigrationLoader
{
    public function __construct(
        private readonly string $basePath,
        private readonly PackageManifest $manifest,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array<string, array<string, Migration>> package => [name => Migration]
     */
    public function loadAll(): array
    {
        $migrations = [];

        foreach ($this->manifest->migrations as $package => $entry) {
            $packageMigrations = [];
            foreach (self::normalizeEntries($entry) as $item) {
                if (self::looksLikeNamespace($item)) {
                    continue; // v2 entries are handled by loadAllV2()
                }
                $resolved = $this->resolvePackageMigrationDirectory($package, $item);
                $packageMigrations += $this->loadFromDirectory($resolved, $package);
            }
            if ($packageMigrations !== []) {
                $migrations[$package] = $packageMigrations;
            }
        }

        $appDir = $this->basePath . '/migrations';
        $appMigrations = $this->loadFromDirectory($appDir, 'app');
        if ($appMigrations !== []) {
            $migrations['app'] = $appMigrations;
        }

        return $migrations;
    }

    /**
     * @return list<MigrationInterfaceV2>
     */
    public function loadAllV2(): array
    {
        $v2 = [];
        foreach ($this->manifest->migrations as $package => $entry) {
            foreach (self::normalizeEntries($entry) as $item) {
                if (! self::looksLikeNamespace($item)) {
                    continue; // path entries handled by loadAll()
                }
                foreach ($this->discoverInNamespace($item, $package) as $migration) {
                    $v2[] = $migration;
                }
            }
        }
        return $v2;
    }

    /**
     * Normalize the per-package manifest value to an ordered list.
     * Single-string form becomes `[string]`; arrays pass through.
     *
     * @param string|list<string> $entry
     * @return list<string>
     */
    private static function normalizeEntries(string|array $entry): array
    {
        if (is_string($entry)) {
            return $entry === '' ? [] : [$entry];
        }

        // Compiler validates the shape (list<string>); we only filter
        // empty strings out here as a tiny safety net for hand-built
        // manifest fixtures.
        $filtered = [];
        foreach ($entry as $v) {
            if ($v !== '') {
                $filtered[] = $v;
            }
        }
        return $filtered;
    }

    /**
     * Heuristic FQCN test — contains a backslash.
     *
     * Per ADR 009, this is the v1 rule. Future iterations may add an
     * explicit `{type: 'namespace'|'path', value: ...}` override syntax
     * if Windows path / nested-namespace ambiguity emerges in practice.
     */
    private static function looksLikeNamespace(string $entry): bool
    {
        return ! self::isAbsolutePath($entry) && str_contains($entry, '\\');
    }

    /**
     * Discover MigrationInterfaceV2 implementations under a namespace
     * prefix via Composer's classmap.
     *
     * Logs a warning (no silent skip) when the namespace exists in
     * the manifest but resolves to zero matching classes — typically
     * indicates a stale manifest entry, a non-optimized autoloader, or
     * a typo in the namespace string.
     *
     * @return list<MigrationInterfaceV2>
     */
    private function discoverInNamespace(string $namespace, string $package): array
    {
        $prefix = rtrim($namespace, '\\') . '\\';
        $migrations = [];

        if (! class_exists(ClassLoader::class)) {
            $this->logger?->warning(
                'MigrationLoader: Composer ClassLoader unavailable; cannot discover v2 namespace.',
                ['package' => $package, 'namespace' => $namespace],
            );
            return [];
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach (array_keys($loader->getClassMap()) as $className) {
                if (! str_starts_with($className, $prefix)) {
                    continue;
                }
                if (! class_exists($className)) {
                    continue;
                }
                $instance = self::instantiate($className);
                if ($instance instanceof MigrationInterfaceV2) {
                    $migrations[] = $instance;
                }
            }
        }

        if ($migrations === []) {
            $this->logger?->warning(sprintf(
                'MigrationLoader: namespace "%s" declared by package "%s" matched zero MigrationInterfaceV2 classes. Run `composer dump-autoload --optimize` and verify the namespace prefix.',
                $namespace,
                $package,
            ));
        }

        return $migrations;
    }

    private static function instantiate(string $className): ?object
    {
        try {
            $reflection = new \ReflectionClass($className);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                return null;
            }
            $ctor = $reflection->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            return $reflection->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePackageMigrationDirectory(string $packageName, string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if (self::isAbsolutePath($path)) {
            return $path;
        }
        if (is_dir($path)) {
            return $path;
        }
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($packageName)) {
            $install = InstalledVersions::getInstallPath($packageName);
            if (is_string($install) && $install !== '') {
                $candidate = rtrim($install, '/\\') . '/' . ltrim($path, '/\\');
                if (is_dir($candidate)) {
                    return $candidate;
                }
            }
        }

        return rtrim($this->basePath, '/\\') . '/vendor/' . $packageName . '/' . ltrim($path, '/\\');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    /**
     * @return array<string, Migration> name => Migration
     */
    private function loadFromDirectory(string $directory, string $package): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.php');
        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $name = $package . ':' . $filename;
            $migration = require $file;

            if (! $migration instanceof Migration) {
                throw new \RuntimeException(sprintf(
                    'Migration file "%s" must return an instance of %s.',
                    $file,
                    Migration::class,
                ));
            }

            $migrations[$name] = $migration;
        }

        return $migrations;
    }
}
