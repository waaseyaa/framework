<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Discovery;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use Waaseyaa\Foundation\Attribute\AsFieldType;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Event\Attribute\Listener;

final class PackageManifestCompiler
{
    private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
    private const FORMATTER_ATTRIBUTE = 'Waaseyaa\\SSR\\Attribute\\AsFormatter';

    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath,
    ) {}

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
        $listeners = [];
        $middleware = [];
        $permissions = [];
        $policies = [];

        // Read installed packages manifest
        $installedPath = $this->basePath . '/vendor/composer/installed.json';
        if (is_file($installedPath)) {
            $installed = json_decode(file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
            $packages = $installed['packages'] ?? $installed;

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

        // Read root composer.json for app-level providers.
        // Composer's installed.json excludes the root package, so app providers
        // declared in the project's extra.waaseyaa.providers must be read separately.
        $rootComposerPath = $this->basePath . '/composer.json';
        if (is_file($rootComposerPath)) {
            try {
                $rootComposer = json_decode(file_get_contents($rootComposerPath), true, 512, JSON_THROW_ON_ERROR);
                $rootExtra = $rootComposer['extra']['waaseyaa'] ?? null;
                if (is_array($rootExtra)) {
                    if (isset($rootExtra['providers'])) {
                        array_push($providers, ...$rootExtra['providers']);
                    }
                    if (isset($rootExtra['commands'])) {
                        array_push($commands, ...$rootExtra['commands']);
                    }
                    if (isset($rootExtra['routes'])) {
                        array_push($routes, ...$rootExtra['routes']);
                    }
                    if (isset($rootExtra['permissions']) && is_array($rootExtra['permissions'])) {
                        foreach ($rootExtra['permissions'] as $permId => $permDef) {
                            $permissions[$permId] = $permDef;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log(sprintf('[Waaseyaa] Failed to read root composer.json: %s', $e->getMessage()));
            }
        }

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

            foreach ($ref->getAttributes(Listener::class) as $attr) {
                try {
                    $instance = $attr->newInstance();
                    $invoke = $ref->getMethod('__invoke');
                    $params = $invoke->getParameters();
                    if (count($params) > 0) {
                        $eventType = $params[0]->getType();
                        if ($eventType instanceof \ReflectionNamedType) {
                            $eventClass = $eventType->getName();
                            $listeners[$eventClass][] = [
                                'class' => $class,
                                'priority' => $instance->priority,
                            ];
                        }
                    }
                } catch (\ReflectionException) {
                    // Skip listeners with missing __invoke or invalid signatures
                    continue;
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

        // Sort listeners by priority (descending)
        foreach ($listeners as &$eventListeners) {
            usort($eventListeners, fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
        }

        return new PackageManifest(
            providers: $providers,
            commands: $commands,
            routes: $routes,
            migrations: $migrations,
            fieldTypes: $fieldTypes,
            formatters: $formatters,
            listeners: $listeners,
            middleware: $middleware,
            permissions: $permissions,
            policies: $policies,
        );
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
        $content = '<?php return ' . var_export($manifest->toArray(), true) . ';' . "\n";
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
                    return PackageManifest::fromArray($data);
                }
            } catch (\Throwable) {
                // Corrupt cache — recompile
            }
        }

        return $this->compileAndCache();
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
        // so app-level policies, listeners, and middleware are discovered.
        $appPrefixes = array_values(array_filter($prefixes, static fn(string $p) => $p !== 'Waaseyaa\\'));
        if ($appPrefixes !== []) {
            $appClasses = $this->scanPsr4Classes($appPrefixes);
            $candidates = array_values(array_unique(array_merge($candidates, $appClasses)));
        }

        if ($candidates === []) {
            // No classmap entries and no app classes — full PSR-4 fallback.
            error_log(
                '[Waaseyaa] PackageManifestCompiler: no discoverable classes found. '
                . 'Falling back to full PSR-4 directory scanning. '
                . 'Run "composer dump-autoload --optimize" for faster, reliable discovery.',
            );
            $candidates = $this->scanPsr4Classes($prefixes);
        }

        return $this->filterDiscoveryClasses($candidates);
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
                    || !empty($ref->getAttributes(Listener::class))
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
            error_log(
                '[Waaseyaa] PackageManifestCompiler: failed to load PSR-4 map: ' . $e->getMessage(),
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
