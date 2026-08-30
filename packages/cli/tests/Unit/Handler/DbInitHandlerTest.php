<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\Tests\Fixtures\RootApplicationV2Migration;
use Waaseyaa\CLI\Tests\Fixtures\RootApplicationV2MigrationAutoloader;
use Waaseyaa\Foundation\Migration\MigrationRepository;

#[CoversClass(DbInitHandler::class)]
final class DbInitHandlerTest extends TestCase
{
    private string $projectRoot;
    private ?ClassLoader $migrationClassLoader = null;
    private ?ClassLoader $unrelatedClassLoader = null;

    protected function setUp(): void
    {
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('d', 32)));
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa-db-init-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot, 0o755, true);
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production', 'database' => '" . $this->projectRoot . "/storage/waaseyaa.sqlite'];\n",
        );
    }

    protected function tearDown(): void
    {
        $this->migrationClassLoader?->unregister();
        $this->unrelatedClassLoader?->unregister();
        putenv('WAASEYAA_APP_SECRET');
        // Best-effort cleanup: the default-sync test boots a ConsoleKernel whose
        // services hold SQLite handles freed only by the GC cycle collector, so
        // on Windows the WAL/SHM files can still be locked at teardown. The leak
        // is process-local and harmless (POSIX unlink-open-file just works on the
        // Linux CI). Don't let temp-dir cleanup fail the test.
        try {
            new Filesystem()->remove($this->projectRoot);
        } catch (IOException) {
            // Intentionally ignored -- see above.
        }
    }

    #[Test]
    public function freshVolumeCreatesDatabaseAndSucceeds(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $this->assertFileDoesNotExist($dbPath);

        // Migration mechanics only — schema sync is exercised separately below.
        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertFileExists($dbPath);
        $this->assertStringContainsString('Created database', $tester->getStdout());
        $this->assertStringContainsString('Database ready', $tester->getStdout());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $this->assertTrue($connection->createSchemaManager()->tablesExist(['waaseyaa_migrations']));
        $connection->close();
    }

    #[Test]
    public function rerunOnInitializedDatabaseIsIdempotent(): void
    {
        // First run (migrations only — schema sync is exercised separately).
        $this->createTester()->executeMap(['--no-sync-schema' => true]);

        // Second run.
        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Database already present', $tester->getStdout());
        $this->assertStringContainsString('No pending migrations', $tester->getStdout());
    }

    #[Test]
    public function applies_root_application_v2_migrations(): void
    {
        // Random-order runs may already have unrelated application loaders.
        $this->unrelatedClassLoader = new ClassLoader($this->projectRoot . '/unrelated-vendor');
        $this->unrelatedClassLoader->register();
        $this->migrationClassLoader = RootApplicationV2MigrationAutoloader::register();
        self::assertArrayNotHasKey(RootApplicationV2Migration::class, $this->unrelatedClassLoader->getClassMap());

        mkdir($this->projectRoot . '/vendor/composer', 0o755, true);
        file_put_contents($this->projectRoot . '/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode([
                'name' => 'acme/application',
                'extra' => ['waaseyaa' => [
                    'migrations' => ['Waaseyaa\\CLI\\Tests\\Fixtures'],
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        new MigrationRepository($connection)->createTable();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $schemaBefore = $connection->fetchAllAssociative('SELECT * FROM sqlite_master ORDER BY name');
        $ledgerBefore = $connection->fetchAllAssociative('SELECT * FROM waaseyaa_migrations');

        $dryRun = $this->createTester()->executeMap(['--dry-run' => true]);
        self::assertSame(0, $dryRun->getExitCode());
        self::assertStringContainsString('acme/application:v2:add-widget-profile', $dryRun->getStdout());
        self::assertSame($schemaBefore, $connection->fetchAllAssociative('SELECT * FROM sqlite_master ORDER BY name'));
        self::assertSame($ledgerBefore, $connection->fetchAllAssociative('SELECT * FROM waaseyaa_migrations'));
        $connection->close();

        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $readConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $columns = array_column(
            $readConnection->executeQuery('PRAGMA table_info(widgets)')->fetchAllAssociative(),
            'name',
        );
        self::assertContains('profile', $columns);
        $dryRun->executeMap(['--dry-run' => true]);
        self::assertStringContainsString('No pending migrations', $dryRun->getStdout());
        $readConnection->close();
    }

    #[Test]
    public function declaring_the_default_root_directory_preserves_applied_ledger_identity(): void
    {
        mkdir($this->projectRoot . '/migrations');
        file_put_contents($this->projectRoot . '/migrations/01_init.php', <<<'PHP'
<?php
return new class extends \Waaseyaa\Foundation\Migration\Migration {
    public function up(\Waaseyaa\Foundation\Migration\SchemaBuilder $schema): void {
        $schema->create('legacy_widgets', function ($table): void { $table->id(); });
    }
};
PHP);
        $first = $this->createTester()->executeMap(['--no-sync-schema' => true]);
        self::assertSame(0, $first->getExitCode());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->projectRoot . '/storage/waaseyaa.sqlite']);
        try {
            $original = $connection->fetchAllAssociative('SELECT * FROM waaseyaa_migrations');
            self::assertSame('app:01_init', $original[0]['migration']);
            mkdir($this->projectRoot . '/vendor/composer', 0o755, true);
            file_put_contents($this->projectRoot . '/vendor/composer/installed.json', '{"packages":[]}');
            file_put_contents($this->projectRoot . '/composer.json', json_encode([
                'name' => 'acme/application',
                'extra' => ['waaseyaa' => ['migrations' => ['migrations', './migrations/']]],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($this->projectRoot . '/migrations/02_next.php', <<<'PHP'
<?php
return new class extends \Waaseyaa\Foundation\Migration\Migration {
    public function up(\Waaseyaa\Foundation\Migration\SchemaBuilder $schema): void {
        $schema->create('next_widgets', function ($table): void { $table->id(); });
    }
};
PHP);
            $upgrade = $this->createTester()->executeMap(['--no-sync-schema' => true]);
            self::assertSame(0, $upgrade->getExitCode());
            self::assertStringContainsString('Ran 1 migration.', $upgrade->getStdout());
            self::assertSame($original, $connection->fetchAllAssociative("SELECT * FROM waaseyaa_migrations WHERE migration = 'app:01_init'"));
            self::assertSame(['app:01_init', 'app:02_next'], $connection->fetchFirstColumn('SELECT migration FROM waaseyaa_migrations ORDER BY migration'));
            $upgrade->executeMap(['--no-sync-schema' => true]);
            self::assertStringContainsString('No pending migrations', $upgrade->getStdout());
        } finally {
            $connection->close();
        }
    }

    // ----- P0-3 (wayfinding-stress-remediation-01KVGK4Q): a fresh db:init must
    // provision entity-storage schema BY DEFAULT, not just migration tables.
    // The schema-creation result for the trail's two-axis shape is proven in
    // entity-storage (TwoAxisSchemaSyncProvisionTest); here we prove the db:init
    // WIRING: schema sync runs by default and is skipped only by --no-sync-schema.

    #[Test]
    public function noSyncSchemaRunsMigrationsOnlyAndSucceeds(): void
    {
        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Database ready', $tester->getStdout());
        $this->assertStringNotContainsString('Schema sync', $tester->getStdout());
    }

    #[Test]
    public function schemaSyncRunsByDefault(): void
    {
        // The default path runs schema sync, which boots a console kernel to
        // enumerate registered entity types. This minimal project root registers
        // no content types, so that boot is rejected by the kernel's
        // content-type guard — which is exactly the evidence that schema sync IS
        // attempted by default (the --no-sync-schema run above skips it and
        // succeeds). A real app boots cleanly and provisions every entity table.
        $tester = $this->createTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No content types are registered');
        $tester->execute([]);
    }

    #[Test]
    public function partiallyInitializedDatabaseIsRefused(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement('CREATE TABLE unrelated_stuff (id INTEGER PRIMARY KEY)');
        $connection->close();

        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('does not look Waaseyaa-initialized', $tester->getStderr());
        $this->assertStringContainsString('Move the file aside', $tester->getStderr());
    }

    /**
     * #2644: the file an earlier kernel boot leaves behind is a valid SQLite
     * database with zero tables. It used to be refused as a foreign database,
     * so an operator who ran any command before `db:init` was told to move
     * aside a file the framework itself had created — with no supported
     * recovery that was not manual filesystem surgery.
     */
    #[Test]
    public function emptyBootstrapDatabaseFromAnEarlierBootIsAdopted(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        // Created exactly the way a kernel boot creates it: opened, no DDL.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeQuery('SELECT 1');
        $connection->close();
        self::assertFileExists($dbPath);

        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Adopting the empty bootstrap database', $tester->getStdout());
        self::assertStringNotContainsString('Move the file aside', $tester->getStderr());
    }

    /**
     * Adoption is safe only because an empty bootstrap file provably holds
     * nothing. `listTableNames()` reports tables alone, so a database whose
     * only object is a view has an empty table list — adopting one would write
     * the migration catalog into somebody else's database, which is precisely
     * what the refusal exists to prevent.
     *
     * @param non-empty-string $occupy DDL or pragma that makes the file foreign
     */
    #[Test]
    #[DataProvider('foreignEmptyTableDatabaseProvider')]
    public function aForeignDatabaseWithNoTablesIsStillRefused(string $occupy): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement($occupy);
        // The precondition that makes this a regression test: the old
        // table-only predicate saw nothing here and adopted the file.
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
        $connection->close();

        $tester = $this->createTester();
        $tester->executeMap(['--no-sync-schema' => true]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('does not look Waaseyaa-initialized', $tester->getStderr());
        self::assertStringContainsString('Move the file aside', $tester->getStderr());
        self::assertStringNotContainsString('Adopting the empty bootstrap database', $tester->getStdout());
    }

    /** @return iterable<string, array{string}> */
    public static function foreignEmptyTableDatabaseProvider(): iterable
    {
        yield 'view only' => ['CREATE VIEW sentinel AS SELECT 1'];
        yield 'stamped application_id' => ['PRAGMA application_id = 252006'];
        yield 'stamped user_version' => ['PRAGMA user_version = 7'];
    }

    #[Test]
    public function dryRunOnFreshVolumeReportsWithoutCreating(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        $tester = $this->createTester();
        $tester->executeMap(['--dry-run' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertFileDoesNotExist($dbPath);
        $this->assertStringContainsString('--dry-run', $tester->getStdout());
        $this->assertStringContainsString('would be created', $tester->getStdout());
        $this->assertStringContainsString('Would run all pending migrations', $tester->getStdout());
    }

    #[Test]
    public function stagingMemoryDatabaseIsRefusedBeforeDryRunOrConnection(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'staging', 'database' => ':memory:'];\n",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB002');
        $this->createTester()->executeMap(['--dry-run' => true]);
    }

    #[Test]
    public function configuredDsnIsRefusedBeforeItCanBeAbsolutizedIntoAPath(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production', 'database' => 'pgsql:host=database'];\n",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB001');
        $this->createTester()->executeMap(['--dry-run' => true]);
    }

    #[Test]
    public function dryRunOnInitializedDatabaseReportsPending(): void
    {
        $this->createTester()->executeMap(['--no-sync-schema' => true]);

        $tester = $this->createTester();
        $tester->executeMap(['--dry-run' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('present and initialized', $tester->getStdout());
        $this->assertStringContainsString('No pending migrations', $tester->getStdout());
    }

    #[Test]
    public function dryRunRefusesAStaleLedgerWithoutUpgradingIt(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->close();

        try {
            $this->createTester()->executeMap(['--dry-run' => true]);
            self::fail('db:init --dry-run silently upgraded a stale ledger.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB105', $exception->getMessage());
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $columns = array_column(
            $connection->executeQuery('PRAGMA table_info(waaseyaa_migrations)')->fetchAllAssociative(),
            'name',
        );
        self::assertNotContains('checksum', $columns);
        self::assertNotContains('diff_hash', $columns);
        $connection->close();
    }

    #[Test]
    public function parentDirectoryNotWritableFailsWithClearMessage(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission enforcement is bypassed for root.');
        }

        $storage = $this->projectRoot . '/storage';
        chmod($storage, 0o500);

        try {
            $tester = $this->createTester();
            $tester->execute([]);

            $this->assertSame(1, $tester->getExitCode());
            $stderr = $tester->getStderr();
            $this->assertStringContainsString('not writable', $stderr);
            $this->assertStringContainsString($storage, $stderr);
        } finally {
            chmod($storage, 0o755);
        }
    }

    #[Test]
    public function concurrentInvocationBailsFast(): void
    {
        $storage = $this->projectRoot . '/storage';
        $lockPath = $storage . '/.db-init.lock';
        $holder = fopen($lockPath, 'c');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            $tester = $this->createTester();
            $tester->execute([]);

            $this->assertSame(1, $tester->getExitCode());
            $this->assertStringContainsString('Another db:init is in progress', $tester->getStderr());
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    // ----- Mission request-surface-hardening (#1650) WP02: path parity -----
    // db:init must resolve through the kernel's canonical resolver: relative
    // config values absolutize against the project root (verbatim before),
    // and Windows absolutes pass through (only `/`-prefixed before).

    #[Test]
    public function dryRunResolvesRelativeConfigValueAgainstProjectRoot(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production', 'database' => './storage/rel.sqlite'];\n",
        );

        $tester = $this->createTester();
        $tester->executeMap(['--dry-run' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString(
            'Database path: ' . $this->projectRoot . '/storage/rel.sqlite',
            $tester->getStdout(),
        );
    }

    #[Test]
    public function dryRunLeavesWindowsDriveLetterConfigValueUntouched(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production', 'database' => 'C:\\\\data\\\\win.sqlite'];\n",
        );

        $tester = $this->createTester();
        $tester->executeMap(['--dry-run' => true]);

        $this->assertStringContainsString('Database path: C:\\data\\win.sqlite', $tester->getStdout());
    }

    #[Test]
    public function dryRunResolvesClimbingEnvValueAgainstProjectRoot(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['environment' => 'production'];\n",
        );
        putenv('WAASEYAA_DB=../escape.sqlite');

        try {
            $tester = $this->createTester();
            $tester->executeMap(['--dry-run' => true]);

            $this->assertStringContainsString(
                'Database path: ' . $this->projectRoot . '/../escape.sqlite',
                $tester->getStdout(),
            );
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    private function createTester(): CliTester
    {
        $handler = new DbInitHandler(projectRoot: $this->projectRoot);
        $definition = new HandlerCommand(
            name: 'db:init',
            description: 'Initialize the database on first deploy and apply pending migrations.',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Show what would happen without creating files or running migrations.',
                ),
                new HandlerOption(
                    name: 'no-sync-schema',
                    mode: HandlerOptionMode::None,
                    description: 'Skip entity-schema materialization and run migrations only.',
                ),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }
            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($definition, $container);
    }

}
