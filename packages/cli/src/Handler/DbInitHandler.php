<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\EnvLoader;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;

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

    public function execute(SymfonyCommandIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');

        EnvLoader::load($this->projectRoot . '/.env');
        $config = $this->loadConfig();
        DatabaseBootstrapper::assertConfiguredPathShape($config);
        $dbPath = $this->resolveDatabasePath($config);
        $environment = SqliteTopology::resolveEnvironment($config);
        SqliteTopology::assertEnvironmentAllowsPath($dbPath, $environment);

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

            $database = DBALDatabase::createSqlite($dbPath, $environment);
            $connection = $database->getConnection();

            if (!$fresh && !$this->looksWaaseyaaInitialized($connection)) {
                if (!$this->isEmptyBootstrapArtifact($connection)) {
                    $io->error(sprintf('Database at %s exists but does not look Waaseyaa-initialized (no waaseyaa_migrations table) and is not empty.', $dbPath));
                    $io->error('Refusing to touch it. Move the file aside (e.g. mv waaseyaa.sqlite waaseyaa.sqlite.bak) and re-run db:init.');
                    return 1;
                }

                // #2644: a completely empty SQLite file is the framework's own
                // artifact, not a foreign database. Any kernel boot creates one
                // — DBALDatabase::createSqlite() opens eagerly and
                // AbstractKernel::boot() calls bootDatabase() before every
                // restricted-discovery guard — so an operator who ran any
                // command before db:init was told to move aside a file they
                // never made. Adoption is safe only because there is provably
                // nothing in it to lose, which is why the predicate inspects
                // every sqlite_schema object rather than tables alone.
                $io->writeln(sprintf('Adopting the empty bootstrap database at %s left by an earlier boot.', $dbPath));
            }

            $repository = new MigrationRepository($connection);
            $repository->installOrUpgradeLedger();

            $manifest = new PackageManifestCompiler(
                basePath: $this->projectRoot,
                storagePath: $this->projectRoot . '/storage',
            )->load();
            $loader = new MigrationLoader($this->projectRoot, $manifest);
            $migrations = $loader->loadAll();
            $v2Migrations = $loader->loadAllV2();

            $compiler = SqliteCompiler::forVersion((string) $connection->fetchOne('SELECT sqlite_version()'));
            $migrator = new Migrator(
                $connection,
                $repository,
                new V2PlanExecutor($connection, $compiler),
            );
            $result = $migrator->run($migrations, $v2Migrations);

            if ($result->count === 0) {
                $io->writeln('No pending migrations.');
            } else {
                foreach ($result->migrations as $name) {
                    $io->writeln(sprintf('  Migrated: %s', $name));
                }
                $label = $result->count === 1 ? 'migration' : 'migrations';
                $io->writeln(sprintf('Ran %d %s.', $result->count, $label));
            }

            // Schema sync runs BY DEFAULT so a fresh db:init fully provisions
            // every registered entity type's storage — including two-axis
            // (revisionable × translatable) revision/translation tables like
            // `wayfinding_trail__translation__revision` — not just the
            // migration-defined tables. `--no-sync-schema` opts out for a
            // migrations-only run; `--sync-schema` is still accepted (now a
            // redundant explicit affirmation) so existing `db:init --sync-schema`
            // invocations keep working. Before this default, a fresh db:init
            // created no entity-storage tables at all and the saved-trail tables
            // needed a manual `schema:sync` / `revisions:enable` step (P0-3,
            // mission wayfinding-stress-remediation-01KVGK4Q).
            if (!(bool) $io->option('no-sync-schema')) {
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
     * just-migrated database — including the two-axis (revisionable ×
     * translatable) revision/translation tables. Runs by default (opt out with
     * `--no-sync-schema`): one `db:init` brings a fresh database fully up —
     * migrations PLUS every registered entity's schema — so app entity types
     * (e.g. the saved-trail `wayfinding_trail`) need no separate `schema:sync`
     * or manual `revisions:enable` step. The schema-sync kernel's DB connection
     * is closed before returning so it never holds a lock on the new file.
     */
    private function syncSchema(SymfonyCommandIO $io): void
    {
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForSchemaSync();

        // Trigger the (lazy) kernel boot and capture the services BEFORE the
        // try/finally. If the boot fails (e.g. a project with no registered
        // content types), the exception propagates cleanly here — the finally
        // must NOT re-call a kernel accessor, or it would re-trigger the boot and
        // mask the real error.
        $entityTypeManager = $kernel->getEntityTypeManager();
        $database = $kernel->getDatabase();

        try {
            $runner = new EntitySchemaSyncRunner(
                $database,
                $entityTypeManager->getFieldRegistry(),
            );
            $report = $runner->run($entityTypeManager->getDefinitions());

            if ($report->created === []) {
                $io->writeln(sprintf('Schema sync: all %d registered entity table(s) already exist.', $report->total()));
            } else {
                $io->writeln(sprintf('Schema sync: created %d table(s):', count($report->created)));
                foreach ($report->created as $table) {
                    $io->writeln(sprintf('  + %s', $table));
                }
            }
        } finally {
            // Release the schema-sync kernel's DB connection (captured above, so
            // this never re-triggers the boot). db:init boots a second kernel
            // only to enumerate entity types; leaving its connection open holds a
            // file lock on the just-created SQLite file (breaks redeploys/cleanup
            // on Windows, where an open handle blocks unlink). The migration
            // flow's own connection is a separate, GC-released local.
            if ($database instanceof DBALDatabase) {
                $database->getConnection()->close();
            }
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
     * Resolve the database path through the kernel's canonical resolver so
     * db:init can never disagree with what a booted kernel opens — relative
     * config AND env values absolutize against the project root, and Windows
     * drive-letter / UNC absolutes pass through untouched (#1650 / FR-007).
     *
     * @param array<string, mixed> $config
     */
    private function resolveDatabasePath(array $config): string
    {
        return DatabaseBootstrapper::resolveDatabasePath($this->projectRoot, $config);
    }

    private function reportDryRun(string $dbPath, SymfonyCommandIO $io): int
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
            $database = DBALDatabase::createSqlite($dbPath, SqliteTopology::resolveEnvironment($this->loadConfig()));
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
        // Validate the existing ledger with metadata reads only. An empty
        // migration source set must not let a stale ledger escape inspection.
        $repository->getCompleted();
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

    private function ensureParentDirectory(string $dbPath, SymfonyCommandIO $io): bool
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

    private function createDatabaseFile(string $dbPath, SymfonyCommandIO $io): bool
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
     * Whether this file is byte-for-byte what the framework's own boot leaves
     * behind: a valid SQLite database with nothing in it and nothing stamped
     * on it.
     *
     * Adoption is safe only because there is provably nothing to lose, so the
     * test is deliberately stricter than "has no tables". It asks
     * `sqlite_schema` directly rather than going through
     * `listTableNames()`, which reports only tables: a database whose sole
     * object is a view has an empty table list, and adopting one would mean
     * writing the migration catalog into somebody else's database — the exact
     * outcome the refusal exists to prevent. Views, indexes, and triggers all
     * count as occupancy.
     *
     * `application_id` and `user_version` are checked for the same reason. Both
     * are zero in a database SQLite has just created; a non-zero value is a
     * deliberate stamp by whoever owns the file, and an empty schema does not
     * make that file ours.
     *
     * Any failure to inspect is treated as occupied, so refusal remains the
     * fail-safe answer.
     */
    private function isEmptyBootstrapArtifact(\Doctrine\DBAL\Connection $connection): bool
    {
        try {
            $objects = $connection->fetchOne(
                "SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
            );
            if (!is_int($objects) && !is_numeric($objects)) {
                return false;
            }
            if ((int) $objects !== 0) {
                return false;
            }

            foreach (['application_id', 'user_version'] as $pragma) {
                $value = $connection->fetchOne(sprintf('PRAGMA %s', $pragma));
                if ((!is_int($value) && !is_numeric($value)) || (int) $value !== 0) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock(string $dbPath, SymfonyCommandIO $io)
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
