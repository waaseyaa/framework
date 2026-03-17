<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Waaseyaa\Foundation\Discovery\PackageManifest;

final class MigrationLoader
{
    public function __construct(
        private readonly string $basePath,
        private readonly PackageManifest $manifest,
    ) {}

    /**
     * @return array<string, array<string, Migration>> package => [name => Migration]
     */
    public function loadAll(): array
    {
        $migrations = [];

        foreach ($this->manifest->migrations as $package => $path) {
            $loaded = $this->loadFromDirectory($path, $package);
            if ($loaded !== []) {
                $migrations[$package] = $loaded;
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
     * @return array<string, Migration> name => Migration
     */
    private function loadFromDirectory(string $directory, string $package): array
    {
        if (!is_dir($directory)) {
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

            if (!$migration instanceof Migration) {
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
