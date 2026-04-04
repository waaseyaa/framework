<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use Waaseyaa\Foundation\Attribute\AsFieldType;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class PackageManifestCompiler
{
    private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
    private const FORMATTER_ATTRIBUTE = 'Waaseyaa\\SSR\\Attribute\\AsFormatter';

    /** @internal Cache file metadata; stripped before {@see PackageManifest::fromArray()} */
    private const MANIFEST_INPUTS_FP_KEY = '_manifest_inputs_fp';

    /** @internal Providers confirmed missing after recompile; prevents repeated recompile on next request */
    private const KNOWN_MISSING_PROVIDERS_KEY = '_known_missing_providers';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Compile the package manifest from composer metadata and attribute scanning.
     */
    public function compile(): PackageManifest
    {
        $providers = [];
        $commands = [];
        $routes = [];
        $migrations = [];
        $fieldTypes = [];
        $formatters = [];
        $middleware = [];
        $permissions = [];
        $policies = [];
        $packageDeclarations = [];
        $packages = [];

        // Read installed packages manifest
        $installedPath = $this->basePath . '/vendor/composer/installed.json';
        if (is_file($installedPath)) {
            $installed = json_decode(file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
            $packages = array_map(
                fn(array $package): array => $this->hydrateInstalledPackageMetadata(
                    package: $package,
                    installedMetadataDir: dirname($installedPath),
                ),
                $installed['packages'] ?? $installed,
            );

            foreach ($packages as $package) {
                $extra = $package['extra']['waaseyaa'] ?? null;
                if ($extra === null) {
                    continue;
                }

                if (isset($extra['providers'])) {
                    array_push($providers, ...$extra['providers']);
                }
                if (isset($extra['commands'])) {
                    array_push($commands, ...$extra['commands']);
                }
                if (isset($extra['routes'])) {
                    array_push($routes, ...$extra['routes']);
                }
                if (isset($extra['migrations'])) {
                    $packageName = $package['name'] ?? 'unknown';
                    $migrations[$packageName] = $extra['migrations'];
                }
                if (isset($extra['permissions']) && is_array($extra['permissions'])) {
                    foreach ($extra['permissions'] as $permId => $permDef) {
                        $permissions[$permId] = $permDef;
                    }
                }
            }
        }

        $packageDeclarations = $this->collectPackageDeclarations($packages);

        // Read root composer.json for app-level providers.
        // Composer's installed.json excludes the root package, so app providers
        // declared in the project's extra.waaseyaa.providers must be read separately.
        $this->mergeRootWaaseyaaIntoLists($providers, $commands, $routes, $permissions, onlyAppendMissingFromRoot: false);

        // Scan classes for attributes
        foreach ($this->scanClasses() as $class) {
            $ref = new \ReflectionClass($class);

            foreach ($ref->getAttributes(AsFieldType::class) as $attr) {
                $instance = $attr->newInstance();
                $fieldTypes[$instance->id] = $class;
            }

            foreach ($ref->getAttributes(self::FORMATTER_ATTRIBUTE) as $attr) {
                $instance = $attr->newInstance();
                if (isset($instance->fieldType) && is_string($instance->fieldType) && $instance->fieldType !== '') {
                    $formatters[$instance->fieldType] = $class;
                }
            }

            foreach ($ref->getAttributes(AsMiddleware::class) as $attr) {
                $instance = $attr->newInstance();
                $middleware[$instance->pipeline][] = [
                    'class' => $class,
                    'priority' => $instance->priority,
                ];
            }

            foreach ($ref->getAttributes(AsEntityType::class) as $attr) {
                // Entity types are tracked in providers for now
            }

            foreach ($ref->getAttributes(self::POLICY_ATTRIBUTE) as $attr) {
                $instance = $attr->newInstance();
                $policies[$class] = $instance->entityTypes;
            }
        }

        // Sort middleware by priority (descending)
        foreach ($middleware as &$stack) {
            usort($stack, fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
        }

        return new PackageManifest(
            providers: $providers,
            commands: $commands,
            routes: $routes,
            migrations: $migrations,
            fieldTypes: $fieldTypes,
            formatters: $formatters,
            middleware: $middleware,
            permissions: $permissions,
            policies: $policies,
            packageDeclarations: $packageDeclarations,
        );
    }

    /**
     * Composer path repositories can leave installed.json without local extra metadata.
     *
     * When install-path is present, merge the on-disk package composer.json so the
     * canonical provider/declaration model reflects the actual local package state.
     *
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function hydrateInstalledPackageMetadata(array $package, string $installedMetadataDir): array
    {
        $installPath = $package['install-path'] ?? null;
        if (!is_string($installPath) || $installPath === '') {
            return $package;
        }

        $composerPath = $this->resolveInstalledPackageComposerPath($installPath, $installedMetadataDir);
        if ($composerPath === null) {
            return $package;
        }

        try {
            $localComposer = json_decode(file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $package;
        }

        if (!is_array($localComposer)) {
            return $package;
        }

        $localExtra = is_array($localComposer['extra'] ?? null) ? $localComposer['extra'] : [];
        $installedExtra = is_array($package['extra'] ?? null) ? $package['extra'] : [];
        $localAutoload = is_array($localComposer['autoload'] ?? null) ? $localComposer['autoload'] : [];
        $installedAutoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];

        return array_replace($localComposer, $package, [
            'name' => $package['name'] ?? $localComposer['name'] ?? null,
            'type' => $package['type'] ?? $localComposer['type'] ?? 'library',
            'autoload' => array_replace_recursive($localAutoload, $installedAutoload),
            'extra' => array_replace_recursive($localExtra, $installedExtra),
        ]);
    }

    private function resolveInstalledPackageComposerPath(string $installPath, string $installedMetadataDir): ?string
    {
        $candidatePaths = [
            $installedMetadataDir . '/' . $installPath . '/composer.json',
            $this->basePath . '/' . ltrim($installPath, './') . '/composer.json',
        ];

        foreach ($candidatePaths as $candidatePath) {
            $resolved = realpath($candidatePath);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Compile and write to cache file.
     *
     * @throws \RuntimeException If the cache directory or file cannot be written
     */
    public function compileAndCache(): PackageManifest
    {
        $manifest = $this->compile();

        $dir = $this->storagePath . '/framework';
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new \RuntimeException(sprintf('Failed to create cache directory: %s', $dir));
        }

        $cachePath = $dir . '/packages.php';
        $payload = $manifest->toArray();
        $payload[self::MANIFEST_INPUTS_FP_KEY] = $this->computeManifestInputsFingerprint();
        $content = '<?php return ' . var_export($payload, true) . ';' . "\n";
        $tmpPath = $cachePath . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write package manifest cache to %s', $cachePath));
        }

        if (!rename($tmpPath, $cachePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('Failed to atomically replace package manifest at %s', $cachePath));
        }

        return $manifest;
    }

    /**
     * Load from cache or compile.
     */
    public function load(): PackageManifest
    {
        $cachePath = $this->storagePath . '/framework/packages.php';
        if (is_file($cachePath)) {
            try {
                $data = require $cachePath;
                if (is_array($data)) {
                    $cachedFp = $data[self::MANIFEST_INPUTS_FP_KEY] ?? null;
                    $knownMissing = $data[self::KNOWN_MISSING_PROVIDERS_KEY] ?? [];
                    unset($data[self::MANIFEST_INPUTS_FP_KEY], $data[self::KNOWN_MISSING_PROVIDERS_KEY]);

                    if ($cachedFp !== null && $cachedFp !== $this->computeManifestInputsFingerprint()) {
                        return $this->compileValidateAndCache($cachePath);
                    }

                    $manifest = PackageManifest::fromArray($data);
                    $manifest = $this->mergeRootWaaseyaaIntoManifest($manifest);

                    return $this->validateCachedProviders($manifest, $cachePath, $knownMissing);
                }
            } catch (StaleManifestException) {
                // New missing providers — fall through to compileAndCache()
            } catch (\Throwable) {
                // Corrupt cache — recompile
            }
        }

        return $this->compileValidateAndCache($cachePath);
    }

    /**
     * Compile, validate providers, stamp known-missing if needed, and return the manifest.
     */
    private function compileValidateAndCache(string $cachePath): PackageManifest
    {
        $manifest = $this->compileAndCache();

        try {
            $this->assertProvidersExist($manifest, $cachePath);
        } catch (StaleManifestException $e) {
            $this->logger->error(sprintf(
                'Provider class(es) not found after recompile: %s. '
                . 'Fix the provider declaration in composer.json or run: php bin/waaseyaa optimize:manifest',
                implode(', ', $e->missingProviders()),
            ));
            $this->stampKnownMissing($e->missingProviders());
        }

        return $manifest;
    }

    /**
     * Validate cached manifest providers, returning the manifest if valid or known-missing,
     * or throwing to trigger recompile for newly missing providers.
     *
     * @param list<string> $knownMissing Providers already identified as permanently missing
     * @throws StaleManifestException When missing providers are new (not previously known)
     */
    private function validateCachedProviders(
        PackageManifest $manifest,
        string $cachePath,
        array $knownMissing,
    ): PackageManifest {
        try {
            $this->assertProvidersExist($manifest, $cachePath);
            return $manifest;
        } catch (StaleManifestException $e) {
            $missing = $e->missingProviders();
            sort($missing);
            $known = $knownMissing;
            sort($known);

            if ($missing === $known) {
                $this->logger->error(sprintf(
                    'Provider class(es) still missing (known): %s. '
                    . 'Fix the provider declaration in composer.json or run: php bin/waaseyaa optimize:manifest',
                    implode(', ', $missing),
                ));
                return $manifest;
            }

            $this->logger->warning(sprintf(
                'Stale package manifest detected (missing: %s). Auto-recompiling.',
                implode(', ', $missing),
            ));
            throw $e;
        }
    }

    /**
     * Record permanently missing providers in the cache file so subsequent
     * requests skip recompilation for the same set of missing classes.
     *
     * @param list<string> $missingProviders
     */
    private function stampKnownMissing(array $missingProviders): void
    {
        $cachePath = $this->storagePath . '/framework/packages.php';
        if (!is_file($cachePath)) {
            return;
        }

        try {
            $data = require $cachePath;
        } catch (\Throwable) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        sort($missingProviders);
        $data[self::KNOWN_MISSING_PROVIDERS_KEY] = $missingProviders;

        $content = '<?php return ' . var_export($data, true) . ';' . "\n";
        $tmpPath = $cachePath . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $content) === false) {
            return;
        }

        if (!rename($tmpPath, $cachePath)) {
            @unlink($tmpPath);
        }
    }

    /**
     * Collect class names from classmap or PSR-4 directories,
     * filtered to those with discovery attributes.
     *
     * Scans Waaseyaa\ framework classes and any app-level namespaces
     * declared in the root composer.json autoload section.
     *
     * @return string[]
     */
    private function scanClasses(): array
    {
        $prefixes = $this->discoveryScanPrefixes();
        $candidates = [];

        // Use classmap for framework classes (populated by Composer without --optimize
        // for polyfills/stubs, and fully populated with --optimize).
        $classmapPath = $this->basePath . '/vendor/composer/autoload_classmap.php';
        if (is_file($classmapPath)) {
            $classMap = require $classmapPath;
            $candidates = array_values(array_filter(
                array_keys($classMap),
                static function (string $c) use ($prefixes): bool {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($c, $prefix)) {
                            return true;
                        }
                    }
                    return false;
                },
            ));
        }

        // App-namespace classes (e.g. Minoo\) typically aren't in the classmap
        // without --optimize. Always scan PSR-4 directories for non-framework prefixes
        // so app-level policies and middleware are discovered.
        $appPrefixes = array_values(array_filter($prefixes, static fn(string $p) => $p !== 'Waaseyaa\\'));
        if ($appPrefixes !== []) {
            $appClasses = $this->scanPsr4Classes($appPrefixes);
            $candidates = array_values(array_unique(array_merge($candidates, $appClasses)));
        }

        if ($candidates === []) {
            // No classmap entries and no app classes — full PSR-4 fallback.
            $this->logger->warning(
                'PackageManifestCompiler: no discoverable classes found. '
                . 'Falling back to full PSR-4 directory scanning. '
                . 'Run "composer dump-autoload --optimize" for faster, reliable discovery.',
            );
            $candidates = $this->scanPsr4Classes($prefixes);
        }

        return $this->filterDiscoveryClasses($candidates);
    }

    private function assertProvidersExist(PackageManifest $manifest, string $cachePath): void
    {
        $missingProviders = array_values(array_filter(
            $manifest->providers,
            static fn(string $providerClass): bool => !class_exists($providerClass),
        ));

        if ($missingProviders !== []) {
            throw new StaleManifestException($missingProviders, $cachePath);
        }
    }

    /**
     * Fingerprint of composer inputs used for declared providers/commands/routes/permissions.
     * When this differs from the value stored in the cache, the manifest must be recompiled.
     */
    private function computeManifestInputsFingerprint(): string
    {
        $composerRaw = $this->readFileRaw($this->basePath . '/composer.json');
        $installedRaw = $this->readFileRaw($this->basePath . '/vendor/composer/installed.json');

        return hash('xxh128', $composerRaw . "\0" . $installedRaw);
    }

    private function readFileRaw(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<string, mixed>|null Root extra.waaseyaa or null if unreadable / absent
     */
    private function readRootWaaseyaaExtra(): ?array
    {
        $rootComposerPath = $this->basePath . '/composer.json';
        if (!is_file($rootComposerPath)) {
            return null;
        }

        try {
            $rootComposer = json_decode(file_get_contents($rootComposerPath), true, 512, JSON_THROW_ON_ERROR);
            $rootExtra = $rootComposer['extra']['waaseyaa'] ?? null;

            return is_array($rootExtra) ? $rootExtra : null;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Failed to read root composer.json: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * Merge root extra.waaseyaa providers, commands, routes, and permissions into the given lists.
     *
     * @param list<string> $providers
     * @param list<string> $commands
     * @param list<string> $routes
     * @param array<string, array{title: string, description?: string}> $permissions
     */
    private function mergeRootWaaseyaaIntoLists(
        array &$providers,
        array &$commands,
        array &$routes,
        array &$permissions,
        bool $onlyAppendMissingFromRoot,
    ): void {
        $rootExtra = $this->readRootWaaseyaaExtra();
        if ($rootExtra === null) {
            return;
        }

        if (isset($rootExtra['providers']) && is_array($rootExtra['providers'])) {
            foreach ($rootExtra['providers'] as $provider) {
                if (!is_string($provider)) {
                    continue;
                }
                if (!$onlyAppendMissingFromRoot || !in_array($provider, $providers, true)) {
                    $providers[] = $provider;
                }
            }
        }

        if (isset($rootExtra['commands']) && is_array($rootExtra['commands'])) {
            foreach ($rootExtra['commands'] as $command) {
                if (!is_string($command)) {
                    continue;
                }
                if (!$onlyAppendMissingFromRoot || !in_array($command, $commands, true)) {
                    $commands[] = $command;
                }
            }
        }

        if (isset($rootExtra['routes']) && is_array($rootExtra['routes'])) {
            foreach ($rootExtra['routes'] as $route) {
                if (!is_string($route)) {
                    continue;
                }
                if (!$onlyAppendMissingFromRoot || !in_array($route, $routes, true)) {
                    $routes[] = $route;
                }
            }
        }

        if (isset($rootExtra['permissions']) && is_array($rootExtra['permissions'])) {
            foreach ($rootExtra['permissions'] as $permId => $permDef) {
                if (is_string($permId) && is_array($permDef)) {
                    $permissions[$permId] = $permDef;
                }
            }
        }
    }

    private function mergeRootWaaseyaaIntoManifest(PackageManifest $manifest): PackageManifest
    {
        $providers = $manifest->providers;
        $commands = $manifest->commands;
        $routes = $manifest->routes;
        $permissions = $manifest->permissions;
        $this->mergeRootWaaseyaaIntoLists($providers, $commands, $routes, $permissions, onlyAppendMissingFromRoot: true);

        return new PackageManifest(
            providers: $providers,
            commands: $commands,
            routes: $routes,
            migrations: $manifest->migrations,
            fieldTypes: $manifest->fieldTypes,
            formatters: $manifest->formatters,
            middleware: $manifest->middleware,
            permissions: $permissions,
            policies: $manifest->policies,
            packageDeclarations: $manifest->packageDeclarations,
        );
    }

    /**
     * Normalize package-surface and activation metadata from installed Composer packages.
     *
     * @param array<int, array<string, mixed>> $packages
     * @return array<string, array{surface: 'aggregate'|'implementation'|'tooling', activation: 'discovery'|'none'|'provider'}>
     */
    private function collectPackageDeclarations(array $packages): array
    {
        $declarations = [];

        foreach ($packages as $package) {
            $name = $package['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $type = is_string($package['type'] ?? null) ? $package['type'] : 'library';
            $extra = is_array($package['extra']['waaseyaa'] ?? null) ? $package['extra']['waaseyaa'] : [];
            $autoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];
            $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

            if ($type === 'metapackage') {
                $declarations[$name] = [
                    'surface' => 'aggregate',
                    'activation' => 'none',
                ];
                continue;
            }

            $hasProviders = is_array($extra['providers'] ?? null) && $extra['providers'] !== [];
            $hasDiscoveryAutoload = $psr4 !== [];

            if ($hasProviders) {
                $declarations[$name] = [
                    'surface' => 'implementation',
                    'activation' => 'provider',
                ];
                continue;
            }

            if ($hasDiscoveryAutoload) {
                $declarations[$name] = [
                    'surface' => 'implementation',
                    'activation' => 'discovery',
                ];
                continue;
            }

            $declarations[$name] = [
                'surface' => 'tooling',
                'activation' => 'none',
            ];
        }

        ksort($declarations);

        return $declarations;
    }

    /**
     * Build the list of namespace prefixes to scan for discovery attributes.
     *
     * Always includes Waaseyaa\, plus any PSR-4 namespaces from the root composer.json.
     *
     * @return string[]
     */
    private function discoveryScanPrefixes(): array
    {
        $prefixes = ['Waaseyaa\\'];

        $rootComposerPath = $this->basePath . '/composer.json';
        if (is_file($rootComposerPath)) {
            try {
                $root = json_decode(file_get_contents($rootComposerPath), true, 512, JSON_THROW_ON_ERROR);
                foreach (array_keys($root['autoload']['psr-4'] ?? []) as $ns) {
                    if (is_string($ns) && $ns !== '' && !str_starts_with($ns, 'Waaseyaa\\')) {
                        $prefixes[] = $ns;
                    }
                }
            } catch (\Throwable) {
                // Root composer.json unreadable — proceed with framework-only scanning
            }
        }

        return $prefixes;
    }

    /**
     * Filter candidate class names to concrete classes with discovery attributes.
     * Skips abstract classes, interfaces, traits, and classes that cannot be reflected.
     *
     * @param string[] $candidates
     * @return string[]
     */
    private function filterDiscoveryClasses(array $candidates): array
    {
        $classes = [];

        foreach ($candidates as $class) {
            try {
                $ref = new \ReflectionClass($class);
                if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                    continue;
                }

                $hasDiscoveryAttribute = !empty($ref->getAttributes(AsFieldType::class))
                    || !empty($ref->getAttributes(AsMiddleware::class))
                    || !empty($ref->getAttributes(AsEntityType::class))
                    || !empty($ref->getAttributes(self::POLICY_ATTRIBUTE))
                    || !empty($ref->getAttributes(self::FORMATTER_ATTRIBUTE));

                if ($hasDiscoveryAttribute) {
                    $classes[] = $class;
                }
            } catch (\ReflectionException) {
                // Skip classes that can't be reflected
                continue;
            }
        }

        return $classes;
    }

    /**
     * Scan PSR-4 directories for discoverable classes.
     *
     * @param string[] $prefixes Namespace prefixes to include.
     * @return string[]
     */
    private function scanPsr4Classes(array $prefixes): array
    {
        $psr4Path = $this->basePath . '/vendor/composer/autoload_psr4.php';
        if (!is_file($psr4Path)) {
            return [];
        }

        try {
            $psr4Map = require $psr4Path;
        } catch (\Throwable $e) {
            $this->logger->error(
                'PackageManifestCompiler: failed to load PSR-4 map: ' . $e->getMessage(),
            );
            return [];
        }

        if (!is_array($psr4Map)) {
            return [];
        }

        $classes = [];

        foreach ($psr4Map as $namespace => $dirs) {
            // Skip test namespaces and namespaces not in scan prefixes.
            if (str_contains($namespace, 'Tests\\')) {
                continue;
            }
            $matched = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($namespace, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relativePath = substr($file->getPathname(), strlen($dir) + 1);
                    $classes[] = $namespace . str_replace(['/', '.php'], ['\\', ''], $relativePath);
                }
            }
        }

        return $classes;
    }
}
