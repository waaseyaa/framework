<?php

declare(strict_types=1);

$vendorRoot = $argv[1] ?? '';
$expectedCommit = $argv[2] ?? '';
$treeManifestPath = $argv[3] ?? '';
if (!is_dir($vendorRoot) || preg_match('/^[a-f0-9]{40}$/D', $expectedCommit) !== 1) {
    fwrite(STDERR, "Installed schema-authority artifact inputs are invalid.\n");
    exit(1);
}

$expectedTrees = [];
foreach (file($treeManifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$package, $tree] = explode("\t", $line, 2);
    $expectedTrees[$package] = $tree;
}
if (array_keys($expectedTrees) !== ['database-legacy', 'foundation', 'cli']) {
    throw new RuntimeException('Installed schema-authority package tree roster is incomplete.');
}

$prefixes = [
    'Waaseyaa\\Database\\' => $vendorRoot . '/waaseyaa/database-legacy/src/',
    'Waaseyaa\\Foundation\\' => $vendorRoot . '/waaseyaa/foundation/src/',
    'Waaseyaa\\CLI\\' => $vendorRoot . '/waaseyaa/cli/src/',
    'Doctrine\\DBAL\\' => $vendorRoot . '/doctrine/dbal/src/',
    'Doctrine\\Deprecations\\' => $vendorRoot . '/doctrine/deprecations/src/',
    'Psr\\Cache\\' => $vendorRoot . '/psr/cache/src/',
    'Psr\\Log\\' => $vendorRoot . '/psr/log/src/',
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

foreach ($expectedTrees as $package => $tree) {
    $manifestPath = $vendorRoot . '/waaseyaa/' . $package . '/composer.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['name'] ?? null) !== 'waaseyaa/' . $package
        || ($manifest['version'] ?? null) !== '1.0.0-alpha.1'
        || ($manifest['extra']['waaseyaa']['artifact-source']['commit'] ?? null) !== $expectedCommit
        || ($manifest['extra']['waaseyaa']['artifact-source']['package_tree'] ?? null) !== $tree
        || isset($manifest['repositories'])
        || preg_match('/^[a-f0-9]{40}$/D', $tree) !== 1
    ) {
        throw new RuntimeException("Installed schema-authority manifest is invalid: {$package}");
    }
}

$classRoots = [
    Waaseyaa\Foundation\Migration\SchemaMutationCoordinator::class => '/waaseyaa/foundation/',
    Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint::class => '/waaseyaa/foundation/',
    Waaseyaa\CLI\Command\Migrate\VerifyRunner::class => '/waaseyaa/cli/',
    Waaseyaa\Database\DBALDatabase::class => '/waaseyaa/database-legacy/',
];
foreach ($classRoots as $class => $fragment) {
    $file = new ReflectionClass($class)->getFileName();
    if (!is_string($file) || !str_contains(str_replace('\\', '/', $file), $fragment)) {
        throw new RuntimeException("{$class} did not load from its installed package artifact.");
    }
}

$databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa-schema-artifact-');
if (!is_string($databasePath)) {
    throw new RuntimeException('Could not allocate installed-artifact SQLite path.');
}

try {
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
    $connection->executeStatement('PRAGMA busy_timeout = 5000');
    $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
    $repository = new Waaseyaa\Foundation\Migration\MigrationRepository($connection);
    $compiler = Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler::forVersion(
        (string) $connection->fetchOne('SELECT sqlite_version()'),
    );
    $migration = new class implements Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2 {
        public function migrationId(): string
        {
            return 'waaseyaa/artifact:v2:add-proof';
        }
        public function package(): string
        {
            return 'waaseyaa/artifact';
        }
        public function dependencies(): array
        {
            return [];
        }
        public function plan(): Waaseyaa\Foundation\Schema\Migration\MigrationPlan
        {
            return new Waaseyaa\Foundation\Schema\Migration\MigrationPlan(
                migrationId: $this->migrationId(),
                package: $this->package(),
                dependencies: [],
                root: new Waaseyaa\Foundation\Schema\Diff\CompositeDiff([
                    new Waaseyaa\Foundation\Schema\Diff\AddColumn(
                        'widgets',
                        'proof',
                        new Waaseyaa\Foundation\Schema\Diff\ColumnSpec(type: 'text', nullable: true),
                    ),
                ]),
            );
        }
    };
    $migrator = new Waaseyaa\Foundation\Migration\Migrator(
        $connection,
        $repository,
        new Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor($connection, $compiler),
    );
    $migrator->run([], [$migration]);

    $verified = new Waaseyaa\CLI\Command\Migrate\VerifyRunner($repository, $compiler)->verify([], [$migration]);
    if ($verified->summary->hasFailure() || $verified->authority->status !== 'match') {
        throw new RuntimeException('Installed artifact could not verify its own authority manifest.');
    }

    $connection->executeStatement('ALTER TABLE widgets ADD COLUMN rogue_runtime_column TEXT');
    $drift = new Waaseyaa\CLI\Command\Migrate\VerifyRunner($repository, $compiler)->verify([], [$migration]);
    if (!$drift->summary->hasFailure() || $drift->authority->status !== 'schema_drift') {
        throw new RuntimeException('Installed artifact failed to reject live schema drift.');
    }
    $connection->close();

    fwrite(STDOUT, "S1 installed-artifact schema authority contract passed.\n");
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
