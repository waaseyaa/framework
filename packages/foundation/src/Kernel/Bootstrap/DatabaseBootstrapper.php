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
        return DBALDatabase::createSqlite($this->resolvePath($projectRoot, $config));
    }

    private function resolvePath(string $projectRoot, array $config): string
    {
        $dbPath = $config['database'] ?? null;
        if ($dbPath === null) {
            $dbPath = getenv('WAASEYAA_DB') ?: $projectRoot . '/waaseyaa.sqlite';
        }

        return $dbPath;
    }
}
