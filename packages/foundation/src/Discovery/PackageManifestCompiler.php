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
     * Collect Waaseyaa class names from classmap or PSR-4 directories,
     * filtered to those with discovery attributes.
     *
     * @return string[]
     */
    private function scanClasses(): array
    {
        // Prefer classmap (populated by composer dump-autoload --optimize).
        // Falls back to PSR-4 scanning if classmap is missing or contains no Waaseyaa\ entries.
        $classmapPath = $this->basePath . '/vendor/composer/autoload_classmap.php';
        if (is_file($classmapPath)) {
            $classMap = require $classmapPath;
            $candidates = array_filter(
                array_keys($classMap),
                fn(string $c) => str_starts_with($c, 'Waaseyaa\\'),
            );
            if ($candidates !== []) {
                return $this->filterDiscoveryClasses($candidates);
            }
        }

        error_log(
            '[Waaseyaa] PackageManifestCompiler: no Waaseyaa classes in autoload_classmap.php. '
            . 'Falling back to PSR-4 directory scanning. '
            . 'Run "composer dump-autoload --optimize" for faster, reliable discovery.',
        );

        return $this->filterDiscoveryClasses($this->scanPsr4Classes());
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
     * Scan PSR-4 directories for Waaseyaa classes.
     *
     * @return string[]
     */
    private function scanPsr4Classes(): array
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
            // Skip non-Waaseyaa namespaces and test namespaces (PSR-4 maps include
            // test directories unlike optimized classmaps)
            if (!str_starts_with($namespace, 'Waaseyaa\\') || str_contains($namespace, 'Tests\\')) {
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
