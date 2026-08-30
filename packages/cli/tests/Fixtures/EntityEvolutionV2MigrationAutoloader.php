<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Fixtures;

use Composer\Autoload\ClassLoader;

/** A scoped application classmap; never mutate other registered loaders. */
final class EntityEvolutionV2MigrationAutoloader
{
    public static function register(): ?ClassLoader
    {
        // An optimized dev autoloader may already own the fixture.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (isset($loader->getClassMap()[EntityEvolutionV2Migration::class])) {
                return null;
            }
        }

        $loader = new ClassLoader(__DIR__ . '/entity-v2-vendor-' . bin2hex(random_bytes(8)));
        $loader->addClassMap([
            EntityEvolutionV2Migration::class => __DIR__ . '/EntityEvolutionV2Migration.php',
        ]);
        $loader->register();

        return $loader;
    }
}
