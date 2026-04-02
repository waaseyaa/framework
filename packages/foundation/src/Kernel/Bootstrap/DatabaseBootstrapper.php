<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

final class DatabaseBootstrapper
{
    /**
     * Create and return the database connection.
     *
     * @param array<string, mixed> $config Application config.
     */
    public function boot(string $projectRoot, array $config): DatabaseInterface
    {
        $path = $this->resolvePath($projectRoot, $config);
        $this->guardMissingProductionSqliteDatabase($path, $config);

        return DBALDatabase::createSqlite($path);
    }

    private function resolvePath(string $projectRoot, array $config): string
    {
        $dbPath = $config['database'] ?? null;
        if ($dbPath === null) {
            $dbPath = getenv('WAASEYAA_DB') ?: $projectRoot . '/storage/waaseyaa.sqlite';
        }

        // Ensure the parent directory exists so SQLite can create the file.
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }

        return $dbPath;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function guardMissingProductionSqliteDatabase(string $path, array $config): void
    {
        if (!$this->isProductionEnvironment($config)) {
            return;
        }

        if ($path === ':memory:' || file_exists($path)) {
            return;
        }

        throw new \RuntimeException(
            sprintf('Database not found at %s. In production, the database must already exist.', $path),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isProductionEnvironment(array $config): bool
    {
        return strtolower($this->resolveEnvironment($config)) === 'production';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveEnvironment(array $config): string
    {
        $env = $config['environment'] ?? getenv('APP_ENV') ?: 'production';

        return is_string($env) && $env !== '' ? $env : 'production';
    }
}
