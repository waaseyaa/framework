<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Discovery;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use Waaseyaa\Foundation\Attribute\AsFieldType;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Event\Attribute\Listener;

final class PackageManifestCompiler
{
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
        $listeners = [];
        $middleware = [];

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
            }
        }

        // Scan classes for attributes
        foreach ($this->scanClasses() as $class) {
            $ref = new \ReflectionClass($class);

            foreach ($ref->getAttributes(AsFieldType::class) as $attr) {
                $instance = $attr->newInstance();
                $fieldTypes[$instance->id] = $class;
            }

            foreach ($ref->getAttributes(Listener::class) as $attr) {
                $instance = $attr->newInstance();
                // Listener classes must implement __invoke(DomainEvent)
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
            listeners: $listeners,
            middleware: $middleware,
        );
    }

    /**
     * Compile and write to cache file.
     */
    public function compileAndCache(): PackageManifest
    {
        $manifest = $this->compile();

        $dir = $this->storagePath . '/framework';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $content = '<?php return ' . var_export($manifest->toArray(), true) . ';' . "\n";
        file_put_contents($dir . '/packages.php', $content);

        return $manifest;
    }

    /**
     * Load from cache or compile.
     */
    public function load(): PackageManifest
    {
        $cachePath = $this->storagePath . '/framework/packages.php';
        if (is_file($cachePath)) {
            return PackageManifest::fromArray(require $cachePath);
        }

        return $this->compileAndCache();
    }

    /**
     * Scan autoloaded namespaces for classes with discovery attributes.
     *
     * @return string[]
     */
    private function scanClasses(): array
    {
        $classes = [];
        $autoloadPath = $this->basePath . '/vendor/composer/autoload_classmap.php';

        if (!is_file($autoloadPath)) {
            return $classes;
        }

        $classMap = require $autoloadPath;

        foreach ($classMap as $class => $file) {
            // Only scan Waaseyaa namespace classes
            if (!str_starts_with($class, 'Waaseyaa\\')) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($class);
                if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                    continue;
                }

                $hasDiscoveryAttribute = !empty($ref->getAttributes(AsFieldType::class))
                    || !empty($ref->getAttributes(Listener::class))
                    || !empty($ref->getAttributes(AsMiddleware::class))
                    || !empty($ref->getAttributes(AsEntityType::class));

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
}
