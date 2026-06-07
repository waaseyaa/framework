<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\EnvLoader;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;

/**
 * Sanctioned first-deploy database initializer.
 *
 * Resolves the SQLite path from the same config chain the HTTP kernel uses,
 * creates the file and its parent directory if missing, and runs all pending
 * migrations through the standard Migrator. Safe to run on every deploy: the
 * Migrator skips already-applied migrations. Refuses to touch a database that
 * exists but does not look Waaseyaa-initialized.
 *
 * Runs outside the normal kernel boot so it can execute under APP_ENV=production
 * without tripping the DatabaseBootstrapper production guard. See ConsoleKernel::shouldUseMinimalConsole.
 */
final class DbInitHandler
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function execute(CliIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');

        EnvLoader::load($this->projectRoot . '/.env');
        $config = $this->loadConfig();
        $dbPath = $this->resolveDatabasePath($config);

        if ($dryRun) {
            return $this->reportDryRun($dbPath, $io);
        }

        if (!$this->ensureParentDirectory($dbPath, $io)) {
            return 1;
        }

        $lockHandle = $this->acquireLock($dbPath, $io);
        if ($lockHandle === null) {
            return 1;
        }

        try {
            $fresh = !is_file($dbPath);
            if ($fresh) {
                if (!$this->createDatabaseFile($dbPath, $io)) {
                    return 1;
                }
                $io->writeln(sprintf('Created database at %s.', $dbPath));
            } else {
                $io->writeln(sprintf('Database already present at %s.', $dbPath));
            }

            $database = DBALDatabase::createSqlite($dbPath);
            $connection = $database->getConnection();

            if (!$fresh && !$this->looksWaaseyaaInitialized($connection)) {
                $io->error(sprintf('Database at %s exists but does not look Waaseyaa-initialized (no waaseyaa_migrations table).', $dbPath));
                $io->error('Refusing to touch it. Move the file aside (e.g. mv waaseyaa.sqlite waaseyaa.sqlite.bak) and re-run db:init.');
                return 1;
            }

            $repository = new MigrationRepository($connection);
            $repository->createTable();

            $manifest = new PackageManifestCompiler(
                basePath: $this->projectRoot,
                storagePath: $this->projectRoot . '/storage',
            )->load();
            $loader = new MigrationLoader($this->projectRoot, $manifest);
            $migrations = $loader->loadAll();

            $migrator = new Migrator($connection, $repository);
            $result = $migrator->run($migrations);

            if ($result->count === 0) {
                $io->writeln('No pending migrations.');
            } else {
                foreach ($result->migrations as $name) {
                    $io->writeln(sprintf('  Migrated: %s', $name));
                }
                $label = $result->count === 1 ? 'migration' : 'migrations';
                $io->writeln(sprintf('Ran %d %s.', $result->count, $label));
            }

            if ((bool) $io->option('sync-schema')) {
                $this->syncSchema($io);
            }

            $io->writeln('Database ready.');
            return 0;
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * Materialize tables for every registered entity type (idempotent).
     *
     * Boots a console kernel so all service-provider and app-defined entity
     * types are registered, then runs the hardened schema sync against the
     * just-migrated database. This is the deploy-time complement to the
     * `schema:sync` command: one `db:init --sync-schema` brings a fresh
     * database fully up — migrations plus every registered entity's schema.
     */
    private function syncSchema(CliIO $io): void
    {
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForCli();

        $entityTypeManager = $kernel->getEntityTypeManager();
        $runner = new EntitySchemaSyncRunner(
            $kernel->getDatabase(),
            $entityTypeManager->getFieldRegistry(),
        );
        $report = $runner->run($entityTypeManager->getDefinitions());

        if ($report->created === []) {
            $io->writeln(sprintf('Schema sync: all %d registered entity table(s) already exist.', $report->total()));
            return;
        }

        $io->writeln(sprintf('Schema sync: created %d table(s):', count($report->created)));
        foreach ($report->created as $table) {
            $io->writeln(sprintf('  + %s', $table));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = $this->projectRoot . '/config/waaseyaa.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                return $loaded;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveDatabasePath(array $config): string
    {
        $configured = $config['database'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $env = getenv('WAASEYAA_DB');
        if (is_string($env) && $env !== '') {
            return $this->absolutize($env);
        }

        return $this->projectRoot . '/storage/waaseyaa.sqlite';
    }

    private function absolutize(string $path): string
    {
        if ($path === ':memory:' || str_starts_with($path, '/')) {
            return $path;
        }
        return rtrim($this->projectRoot, '/') . '/' . ltrim($path, './');
    }

    private function reportDryRun(string $dbPath, CliIO $io): int
    {
        $io->writeln('--dry-run: no changes will be made.');
        $io->writeln(sprintf('Database path: %s', $dbPath));

        $parent = dirname($dbPath);
        $io->writeln(sprintf('Parent directory: %s (%s)', $parent, is_dir($parent) ? 'exists' : 'would be created'));

        if ($dbPath === ':memory:') {
            $io->writeln('Target is in-memory; nothing to persist.');
            return 0;
        }

        if (!is_file($dbPath)) {
            $io->writeln('Database file: absent (would be created).');
            $io->writeln('Would run all pending migrations on the new database.');
            return 0;
        }

        try {
            $database = DBALDatabase::createSqlite($dbPath);
            $connection = $database->getConnection();
        } catch (\Throwable $e) {
            $io->error(sprintf('Cannot open existing database: %s', $e->getMessage()));
            return 1;
        }

        if (!$this->looksWaaseyaaInitialized($connection)) {
            $io->error('Database file exists but is not Waaseyaa-initialized.');
            $io->error('db:init would refuse. Move the file aside and re-run.');
            return 1;
        }

        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $manifest = new PackageManifestCompiler(
            basePath: $this->projectRoot,
            storagePath: $this->projectRoot . '/storage',
        )->load();
        $migrations = new MigrationLoader($this->projectRoot, $manifest)->loadAll();

        $pending = [];
        foreach ($migrations as $package => $set) {
            foreach ($set as $name => $_migration) {
                if (!$repository->hasRun($name)) {
                    $pending[] = $name;
                }
            }
        }

        $io->writeln('Database file: present and initialized.');
        if ($pending === []) {
            $io->writeln('No pending migrations.');
        } else {
            $io->writeln(sprintf('Would run %d pending migration(s):', count($pending)));
            foreach ($pending as $name) {
                $io->writeln(sprintf('  - %s', $name));
            }
        }

        return 0;
    }

    private function ensureParentDirectory(string $dbPath, CliIO $io): bool
    {
        if ($dbPath === ':memory:') {
            return true;
        }

        $parent = dirname($dbPath);
        if (!is_dir($parent)) {
            if (!@mkdir($parent, 0o755, recursive: true) && !is_dir($parent)) {
                $io->error(sprintf('Cannot create parent directory: %s', $parent));
                $io->error(sprintf('Expected writable by user: %s (uid %d).', $this->processUserName(), $this->processUid()));
                return false;
            }
        }

        if (!is_writable($parent)) {
            $io->error(sprintf('Parent directory is not writable: %s', $parent));
            $io->error(sprintf('Expected writable by user: %s (uid %d). Fix directory permissions and retry.', $this->processUserName(), $this->processUid()));
            return false;
        }

        return true;
    }

    private function createDatabaseFile(string $dbPath, CliIO $io): bool
    {
        if ($dbPath === ':memory:') {
            return true;
        }

        if (@touch($dbPath) === false) {
            $io->error(sprintf('Cannot create database file: %s', $dbPath));
            $io->error(sprintf('Expected writable by user: %s (uid %d). Fix permissions and retry.', $this->processUserName(), $this->processUid()));
            return false;
        }

        return true;
    }

    private function looksWaaseyaaInitialized(\Doctrine\DBAL\Connection $connection): bool
    {
        try {
            return $connection->createSchemaManager()->tablesExist(['waaseyaa_migrations']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock(string $dbPath, CliIO $io)
    {
        if ($dbPath === ':memory:') {
            $memoryHandle = fopen('php://memory', 'r+');
            return $memoryHandle === false ? null : $memoryHandle;
        }

        $parent = dirname($dbPath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0o755, recursive: true);
        }

        $lockPath = $parent . '/.db-init.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            $io->error(sprintf('Cannot open lock file: %s', $lockPath));
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $io->error(sprintf('Another db:init is in progress (lock held on %s). Exiting.', $lockPath));
            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function processUserName(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return $info['name'];
            }
        }
        $user = getenv('USER');
        if (!is_string($user) || $user === '') {
            $user = getenv('USERNAME');
        }
        return is_string($user) && $user !== '' ? $user : '(unknown)';
    }

    private function processUid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        return -1;
    }
}
