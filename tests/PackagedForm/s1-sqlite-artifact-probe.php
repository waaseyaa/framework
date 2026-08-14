<?php

declare(strict_types=1);

$packageRoot = $argv[1] ?? '';
$thirdPartyRoot = $argv[2] ?? '';
$expectedCommit = $argv[3] ?? '';
$expectedPackageTree = $argv[4] ?? '';
$dependencyManifestPath = $argv[5] ?? '';
$expectedDependencyManifestSha256 = $argv[6] ?? '';
if (!is_file($packageRoot . '/composer.json') || !is_dir($thirdPartyRoot)) {
    fwrite(STDERR, "Installed-artifact package or dependency root is missing.\n");
    exit(1);
}
$dependencyManifest = json_decode(
    (string) file_get_contents($dependencyManifestPath),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (hash_file('sha256', $dependencyManifestPath) !== $expectedDependencyManifestSha256
    || ($dependencyManifest['schema_version'] ?? null) !== 1
    || count($dependencyManifest['dependencies'] ?? []) !== 4
) {
    fwrite(STDERR, "Installed artifact dependency manifest is invalid.\n");
    exit(1);
}
$dependencyNames = array_column($dependencyManifest['dependencies'], 'name');
sort($dependencyNames);
if ($dependencyNames !== ['doctrine/dbal', 'doctrine/deprecations', 'psr/cache', 'psr/log']) {
    fwrite(STDERR, "Installed artifact dependency roster is incomplete.\n");
    exit(1);
}

$prefixes = [
    'Waaseyaa\\Database\\' => $packageRoot . '/src/',
    'Doctrine\\DBAL\\' => $thirdPartyRoot . '/doctrine/dbal/src/',
    'Doctrine\\Deprecations\\' => $thirdPartyRoot . '/doctrine/deprecations/src/',
    'Psr\\Cache\\' => $thirdPartyRoot . '/psr/cache/src/',
    'Psr\\Log\\' => $thirdPartyRoot . '/psr/log/src/',
];
spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$canonicalPath = static function (string $path): string {
    $resolved = realpath($path);

    return is_string($resolved) ? $resolved : '/missing';
};

$manifest = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['name'] ?? null) !== 'waaseyaa/database-legacy'
    || ($manifest['version'] ?? null) !== '1.0.0-alpha.1'
    || ($manifest['autoload']['psr-4']['Waaseyaa\\Database\\'] ?? null) !== 'src/'
    || ($manifest['extra']['waaseyaa']['artifact-source']['commit'] ?? null) !== $expectedCommit
    || ($manifest['extra']['waaseyaa']['artifact-source']['package_tree'] ?? null) !== $expectedPackageTree
    || preg_match('/^[0-9a-f]{40}$/D', $expectedCommit) !== 1
    || preg_match('/^[0-9a-f]{40}$/D', $expectedPackageTree) !== 1
) {
    fwrite(STDERR, "Installed artifact manifest does not describe the expected package.\n");
    exit(1);
}

$classFile = new ReflectionClass(Waaseyaa\Database\SqliteTopology::class)->getFileName();
if (!is_string($classFile) || !str_starts_with($canonicalPath($classFile), $canonicalPath($packageRoot))) {
    fwrite(STDERR, "SqliteTopology did not load from the installed package artifact.\n");
    exit(1);
}
$middlewareFile = new ReflectionClass(Waaseyaa\Database\SqliteDriverMiddleware::class)->getFileName();
if (!is_string($middlewareFile) || !str_starts_with($canonicalPath($middlewareFile), $canonicalPath($packageRoot))) {
    fwrite(STDERR, "SqliteDriverMiddleware did not load from the installed package artifact.\n");
    exit(1);
}

$path = tempnam(sys_get_temp_dir(), 'waaseyaa-s1-artifact-');
if ($path === false) {
    throw new RuntimeException('Could not allocate the disposable SQLite path.');
}
unlink($path);

try {
    $database = Waaseyaa\Database\DBALDatabase::createSqlite($path);
    $connection = $database->getConnection();
    $actual = [
        'foreign_keys' => (int) $connection->fetchOne('PRAGMA foreign_keys'),
        'busy_timeout' => (int) $connection->fetchOne('PRAGMA busy_timeout'),
        'journal_mode' => strtolower((string) $connection->fetchOne('PRAGMA journal_mode')),
    ];
    $expected = ['foreign_keys' => 1, 'busy_timeout' => 5000, 'journal_mode' => 'wal'];
    if ($actual !== $expected) {
        throw new RuntimeException('Installed artifact produced unexpected PRAGMAs: ' . json_encode($actual));
    }
    $connection->close();
    $connection->executeQuery('SELECT 1')->fetchOne();
    Waaseyaa\Database\SqliteTopology::assertEffectivePragmas($connection, fileBacked: true);

    try {
        Waaseyaa\Database\DBALDatabase::createSqlite(':memory:', 'staging');
        throw new RuntimeException('Installed artifact accepted staging :memory:.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), Waaseyaa\Database\SqliteTopology::PRODUCTION_MEMORY)) {
            throw $exception;
        }
    }

    foreach (['pgsql:host=database', 'file:///network/database.sqlite', '//server/share/database.sqlite'] as $refused) {
        try {
            Waaseyaa\Database\DBALDatabase::createSqlite($refused);
            throw new RuntimeException("Installed artifact accepted refused path: {$refused}");
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), Waaseyaa\Database\SqliteTopology::INVALID_PATH)) {
                throw $exception;
            }
        }
    }

    fwrite(STDOUT, "S1 installed-artifact SQLite contract passed.\n");
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
        if (is_file($candidate)) {
            unlink($candidate);
        }
    }
}
