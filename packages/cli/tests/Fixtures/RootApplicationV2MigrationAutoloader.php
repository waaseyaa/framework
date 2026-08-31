<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Fixtures;

use Composer\Autoload\ClassLoader;

/** A scoped application classmap; never mutate other registered loaders. */
final class RootApplicationV2MigrationAutoloader
{
    public static function register(): ?ClassLoader
    {
        // An optimized dev autoloader may already own the fixture.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if (isset($loader->getClassMap()[RootApplicationV2Migration::class])) {
                return null;
            }
        }

        $loader = new ClassLoader(__DIR__ . '/root-v2-vendor-' . bin2hex(random_bytes(8)));
        $loader->addClassMap([
            RootApplicationV2Migration::class => __DIR__ . '/RootApplicationV2Migration.php',
        ]);
        $loader->register();

        return $loader;
    }
}
