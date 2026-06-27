<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Discovery;

use Waaseyaa\Plugin\Definition\PluginDefinition;

final class AttributeDiscovery implements PluginDiscoveryInterface
{
    /**
     * @param string[] $directories Directories to scan for plugin classes
     * @param string $attributeClass The attribute class to look for (FQCN)
     */
    public function __construct(
        private readonly array $directories,
        private readonly string $attributeClass,
    ) {}

    public function getDefinitions(): array
    {
        $definitions = [];

        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $this->scanDirectory($directory, $definitions);
        }

        return $definitions;
    }

    private function scanDirectory(string $directory, array &$definitions): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->extractClassName($file->getPathname());
            if ($className === null) {
                continue;
            }

            $this->processClass($className, $definitions);
        }
    }

    private function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        $class = '';

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        if (preg_match('/(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    private function processClass(string $className, array &$definitions): void
    {
        try {
            // class_exists() triggers PHP's autoloader. When a scanned class
            // extends a dev-only parent (e.g. PHPUnit's TestCase) that is absent
            // in a production install, PHP throws a fatal \Error — NOT a
            // \ReflectionException — at class-definition time. Wrapping the
            // entire region in catch (\Throwable) keeps boot alive by silently
            // skipping any class that cannot be loaded, reflected, or instantiated
            // via its attribute. This mirrors the guard in PackageManifestCompiler
            // that fixed the alpha.106→107 production outage (waaseyaa/graphql).
            if (!class_exists($className)) {
                return;
            }

            $reflection = new \ReflectionClass($className);
            $attributes = $reflection->getAttributes($this->attributeClass, \ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $definition = new PluginDefinition(
                    id: $instance->id,
                    label: $instance->label,
                    class: $className,
                    description: $instance->description,
                    package: $instance->package,
                );
                $definitions[$definition->id] = $definition;
            }
        } catch (\Throwable) {
            return;
        }
    }
}
