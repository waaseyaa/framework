# Migration Protocol Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the existing migration engine with app-level discovery, CLI wiring, and transaction safety.

**Architecture:** The migration engine (`Migrator`, `MigrationRepository`, `SchemaBuilder`) already exists in `packages/foundation/src/Migration/`. We add a `MigrationLoader` for discovery, wire it into `AbstractKernel::boot()` after `compileManifest()`, add three CLI commands (`migrate`, `migrate:rollback`, `migrate:status`), wrap each migration in a transaction, and update `make:migration` to write files.

**Tech Stack:** PHP 8.3, Doctrine DBAL, Symfony Console 7.x, PHPUnit 10.5, SQLite

**Spec:** `docs/superpowers/specs/2026-03-17-migration-protocol-design.md`

---

### Task 1: SchemaBuilder::getConnection() escape hatch

**Files:**
- Modify: `packages/foundation/src/Migration/SchemaBuilder.php`
- Modify: `packages/foundation/tests/Unit/Migration/SchemaBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Add to `SchemaBuilderTest.php`:

```php
#[Test]
public function getConnectionReturnsTheDbalConnection(): void
{
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $schema = new SchemaBuilder($connection);

    $this->assertSame($connection, $schema->getConnection());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter getConnectionReturnsTheDbalConnection`
Expected: FAIL — method `getConnection` does not exist.

- [ ] **Step 3: Add getConnection() to SchemaBuilder**

In `packages/foundation/src/Migration/SchemaBuilder.php`, add after the `hasColumn()` method:

```php
public function getConnection(): Connection
{
    return $this->connection;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter getConnectionReturnsTheDbalConnection`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Migration/SchemaBuilder.php packages/foundation/tests/Unit/Migration/SchemaBuilderTest.php
git commit -m "feat: expose DBAL Connection via SchemaBuilder::getConnection()"
```

---

### Task 2: Transaction wrapping in Migrator

**Files:**
- Modify: `packages/foundation/src/Migration/Migrator.php`
- Modify: `packages/foundation/tests/Unit/Migration/MigratorTest.php`

- [ ] **Step 1: Write the failing test for transaction on run()**

Add to `MigratorTest.php`:

```php
#[Test]
public function runRollsBackSchemaChangeWhenMigrationFails(): void
{
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $repository = new MigrationRepository($connection);
    $repository->createTable();
    $migrator = new Migrator($connection, $repository);

    $failingMigration = new class extends Migration {
        public function up(SchemaBuilder $schema): void
        {
            $schema->create('should_not_exist', function ($table) {
                $table->id();
            });
            throw new \RuntimeException('Intentional failure');
        }
    };

    try {
        $migrator->run(['app' => ['app:20260317_fail' => $failingMigration]]);
    } catch (\RuntimeException) {
        // expected
    }

    $schema = new SchemaBuilder($connection);
    $this->assertFalse($schema->hasTable('should_not_exist'));
    $this->assertFalse($repository->hasRun('app:20260317_fail'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter runRollsBackSchemaChangeWhenMigrationFails`
Expected: FAIL — `assertFalse($schema->hasTable('should_not_exist'))` fails because the CREATE TABLE is committed even though `up()` throws (no transaction wrapping). The `hasRun` assertion would already pass since the exception prevents `record()` from being reached — the transaction wrapping is needed for the DDL rollback.

- [ ] **Step 3: Write the failing test for transaction on rollback()**

Add to `MigratorTest.php`:

```php
#[Test]
public function rollbackRollsBackOnFailure(): void
{
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $repository = new MigrationRepository($connection);
    $repository->createTable();
    $migrator = new Migrator($connection, $repository);

    // First, run a migration that creates a table
    $migration = new class extends Migration {
        public function up(SchemaBuilder $schema): void
        {
            $schema->create('rollback_test', function ($table) {
                $table->id();
            });
        }

        public function down(SchemaBuilder $schema): void
        {
            $schema->drop('rollback_test');
            throw new \RuntimeException('Intentional rollback failure');
        }
    };

    $migrator->run(['app' => ['app:20260317_rollback_test' => $migration]]);

    try {
        $migrator->rollback(['app' => ['app:20260317_rollback_test' => $migration]]);
    } catch (\RuntimeException) {
        // expected
    }

    // Table should still exist because rollback failed and was rolled back
    $schema = new SchemaBuilder($connection);
    $this->assertTrue($schema->hasTable('rollback_test'));
    // Migration record should still exist
    $this->assertTrue($repository->hasRun('app:20260317_rollback_test'));
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter rollbackRollsBackOnFailure`
Expected: FAIL — table is dropped and record removed despite the exception.

- [ ] **Step 5: Wrap run() in transaction**

In `Migrator::run()`, replace the migration execution block (lines 30-33):

```php
// Old:
$schema = new SchemaBuilder($this->connection);
$migration->up($schema);
$this->repository->record($name, $package, $batch);
$ran[] = $name;

// New:
$schema = new SchemaBuilder($this->connection);
$this->connection->transactional(function () use ($migration, $schema, $name, $package, $batch): void {
    $migration->up($schema);
    $this->repository->record($name, $package, $batch);
});
$ran[] = $name;
```

- [ ] **Step 6: Wrap rollback() in transaction**

In `Migrator::rollback()`, replace the rollback execution block (lines 53-59):

```php
// Old:
$name = $record['migration'];
if (isset($flat[$name])) {
    $schema = new SchemaBuilder($this->connection);
    $flat[$name]->down($schema);
}
$this->repository->remove($name);
$rolledBack[] = $name;

// New:
$name = $record['migration'];
$this->connection->transactional(function () use ($flat, $name): void {
    if (isset($flat[$name])) {
        $schema = new SchemaBuilder($this->connection);
        $flat[$name]->down($schema);
    }
    $this->repository->remove($name);
});
$rolledBack[] = $name;
```

- [ ] **Step 7: Run all migration tests**

Run: `./vendor/bin/phpunit --filter MigratorTest`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add packages/foundation/src/Migration/Migrator.php packages/foundation/tests/Unit/Migration/MigratorTest.php
git commit -m "feat: wrap each migration up/down in a database transaction"
```

---

### Task 3: Extend Migrator::status() return type

**Files:**
- Modify: `packages/foundation/src/Migration/Migrator.php`
- Modify: `packages/foundation/src/Migration/MigrationRepository.php`
- Modify: `packages/foundation/tests/Unit/Migration/MigratorTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MigratorTest.php`:

```php
#[Test]
public function statusReturnsCompletedWithMetadata(): void
{
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $repository = new MigrationRepository($connection);
    $repository->createTable();
    $migrator = new Migrator($connection, $repository);

    $migration = new class extends Migration {
        public function up(SchemaBuilder $schema): void {}
    };

    $migrations = ['app' => ['app:20260317_test' => $migration]];
    $migrator->run($migrations);

    $status = $migrator->status($migrations);

    $this->assertSame([], $status['pending']);
    $this->assertCount(1, $status['completed']);
    $this->assertSame('app:20260317_test', $status['completed'][0]['migration']);
    $this->assertSame('app', $status['completed'][0]['package']);
    $this->assertSame(1, $status['completed'][0]['batch']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter statusReturnsCompletedWithMetadata`
Expected: FAIL — `completed` currently returns `list<string>`, not `list<array>`.

- [ ] **Step 3: Add getCompletedWithDetails() to MigrationRepository**

In `MigrationRepository.php`, add:

```php
/** @return list<array{migration: string, package: string, batch: int}> */
public function getCompletedWithDetails(): array
{
    $result = $this->connection->executeQuery(
        'SELECT migration, package, batch FROM ' . self::TABLE . ' ORDER BY id',
    );
    return $result->fetchAllAssociative();
}
```

- [ ] **Step 4: Update Migrator::status() to use richer return**

In `Migrator.php`, replace the `status()` method:

```php
/**
 * @param array<string, array<string, Migration>> $migrations
 * @return array{pending: list<string>, completed: list<array{migration: string, package: string, batch: int}>}
 */
public function status(array $migrations): array
{
    $completedDetails = $this->repository->getCompletedWithDetails();
    $completedNames = array_column($completedDetails, 'migration');
    $all = array_keys($this->flattenMigrations($migrations));
    $pending = array_values(array_diff($all, $completedNames));

    return ['pending' => $pending, 'completed' => $completedDetails];
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --filter MigratorTest`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add packages/foundation/src/Migration/Migrator.php packages/foundation/src/Migration/MigrationRepository.php packages/foundation/tests/Unit/Migration/MigratorTest.php
git commit -m "feat: extend Migrator::status() to return batch and package metadata"
```

---

### Task 4: MigrationLoader

**Files:**
- Create: `packages/foundation/src/Migration/MigrationLoader.php`
- Create: `packages/foundation/tests/Unit/Migration/MigrationLoaderTest.php`

- [ ] **Step 1: Write tests for MigrationLoader**

Create `packages/foundation/tests/Unit/Migration/MigrationLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\Migration;

#[CoversClass(MigrationLoader::class)]
final class MigrationLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_migration_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function loadsAppMigrationsFromMigrationsDirectory(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_143000_create_posts.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('app:20260317_143000_create_posts', $all['app']);
        $this->assertInstanceOf(Migration::class, $all['app']['app:20260317_143000_create_posts']);
    }

    #[Test]
    public function loadsPackageMigrations(): void
    {
        $pkgDir = $this->tempDir . '/vendor/waaseyaa/node/migrations';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/20260317_100000_create_nodes.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest(migrations: ['waaseyaa/node' => $pkgDir]);
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertArrayHasKey('waaseyaa/node', $all);
        $this->assertArrayHasKey('waaseyaa/node:20260317_100000_create_nodes', $all['waaseyaa/node']);
    }

    #[Test]
    public function appMigrationsRunAfterPackageMigrations(): void
    {
        // Create package migration
        $pkgDir = $this->tempDir . '/vendor/waaseyaa/node/migrations';
        mkdir($pkgDir, 0777, true);
        file_put_contents($pkgDir . '/20260317_100000_create_nodes.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        // Create app migration
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_090000_early_app.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest(migrations: ['waaseyaa/node' => $pkgDir]);
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        // 'app' key must come after package keys
        $keys = array_keys($all);
        $this->assertSame('waaseyaa/node', $keys[0]);
        $this->assertSame('app', $keys[1]);
    }

    #[Test]
    public function handlesMissingMigrationsDirectory(): void
    {
        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertSame([], $all);
    }

    #[Test]
    public function sortsFilesAlphabeticallyWithinPackage(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260318_second.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);
        file_put_contents($migrationsDir . '/20260317_first.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $names = array_keys($all['app']);
        $this->assertSame('app:20260317_first', $names[0]);
        $this->assertSame('app:20260318_second', $names[1]);
    }

    #[Test]
    public function throwsOnInvalidMigrationFile(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/20260317_bad.php', '<?php return "not a migration";');

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/20260317_bad\.php/');
        $loader->loadAll();
    }

    #[Test]
    public function ignoresNonPhpFiles(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        mkdir($migrationsDir);
        file_put_contents($migrationsDir . '/README.md', '# Migrations');
        file_put_contents($migrationsDir . '/20260317_valid.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        PHP);

        $manifest = new PackageManifest();
        $loader = new MigrationLoader($this->tempDir, $manifest);
        $all = $loader->loadAll();

        $this->assertCount(1, $all['app']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter MigrationLoaderTest`
Expected: FAIL — class `MigrationLoader` does not exist.

- [ ] **Step 3: Implement MigrationLoader**

Create `packages/foundation/src/Migration/MigrationLoader.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter MigrationLoaderTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Migration/MigrationLoader.php packages/foundation/tests/Unit/Migration/MigrationLoaderTest.php
git commit -m "feat: add MigrationLoader for package and app migration discovery"
```

---

### Task 5: Kernel integration — bootMigrations()

**Files:**
- Modify: `packages/foundation/src/Kernel/AbstractKernel.php`

- [ ] **Step 1: Add properties for migration components**

In `AbstractKernel`, add two new protected properties alongside the existing ones:

```php
protected Migrator $migrator;
protected MigrationLoader $migrationLoader;
```

Add the necessary imports at the top:

```php
use Doctrine\DBAL\DriverManager;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
```

- [ ] **Step 2: Add bootMigrations() method**

Add after `compileManifest()`:

```php
protected function bootMigrations(): void
{
    $dbPath = $this->config['database'] ?? null;
    if ($dbPath === null) {
        $dbPath = getenv('WAASEYAA_DB') ?: $this->projectRoot . '/waaseyaa.sqlite';
    }

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'path' => $dbPath,
    ]);

    $repository = new MigrationRepository($connection);
    $repository->createTable();

    $this->migrationLoader = new MigrationLoader($this->projectRoot, $this->manifest);
    $this->migrator = new Migrator($connection, $repository);
}
```

- [ ] **Step 3: Wire bootMigrations() into boot()**

In `boot()`, add `$this->bootMigrations();` after `$this->compileManifest();` (after line 77).

- [ ] **Step 4: Add accessor methods**

```php
public function getMigrator(): Migrator
{
    return $this->migrator;
}

public function getMigrationLoader(): MigrationLoader
{
    return $this->migrationLoader;
}
```

- [ ] **Step 5: Run existing tests to check for regressions**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All PASS — bootMigrations() is a no-op unless the kernel is fully booted.

- [ ] **Step 6: Commit**

```bash
git add packages/foundation/src/Kernel/AbstractKernel.php
git commit -m "feat: wire migration system into kernel boot sequence"
```

---

### Task 6: MigrateCommand

**Files:**
- Create: `packages/cli/src/Command/MigrateCommand.php`
- Create: `packages/cli/tests/Unit/Command/MigrateCommandTest.php`

- [ ] **Step 1: Write the test**

Create `packages/cli/tests/Unit/Command/MigrateCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\MigrateCommand;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateCommand::class)]
final class MigrateCommandTest extends TestCase
{
    #[Test]
    public function runsPendingMigrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test_table', function ($table) {
                    $table->id();
                });
            }
        };

        $migrations = ['app' => ['app:20260317_create_test' => $migration]];

        $command = new MigrateCommand($migrator, fn () => $migrations);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('app:20260317_create_test', $tester->getDisplay());
        $this->assertStringContainsString('Ran 1 migration', $tester->getDisplay());
    }

    #[Test]
    public function reportsNothingToMigrate(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $command = new MigrateCommand($migrator, fn () => []);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter MigrateCommandTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement MigrateCommand**

Create `packages/cli/src/Command/MigrateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Migration\Migrator;

#[AsCommand(
    name: 'migrate',
    description: 'Run pending database migrations',
)]
final class MigrateCommand extends Command
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
    ) {
        $this->migrationsProvider = $migrationsProvider;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrations = ($this->migrationsProvider)();
        $result = $this->migrator->run($migrations);

        if ($result->count === 0) {
            $output->writeln('Nothing to migrate.');
            return self::SUCCESS;
        }

        foreach ($result->migrations as $name) {
            $output->writeln("  Migrated: {$name}");
        }

        $label = $result->count === 1 ? 'migration' : 'migrations';
        $output->writeln("Ran {$result->count} {$label}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter MigrateCommandTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/cli/src/Command/MigrateCommand.php packages/cli/tests/Unit/Command/MigrateCommandTest.php
git commit -m "feat: add migrate CLI command"
```

---

### Task 7: MigrateRollbackCommand

**Files:**
- Create: `packages/cli/src/Command/MigrateRollbackCommand.php`
- Create: `packages/cli/tests/Unit/Command/MigrateRollbackCommandTest.php`

- [ ] **Step 1: Write the test**

Create `packages/cli/tests/Unit/Command/MigrateRollbackCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\MigrateRollbackCommand;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateRollbackCommand::class)]
final class MigrateRollbackCommandTest extends TestCase
{
    #[Test]
    public function rollsBackLastBatch(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('rollback_table', function ($table) {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('rollback_table');
            }
        };

        $migrations = ['app' => ['app:20260317_create_rollback' => $migration]];
        $migrator->run($migrations);

        $command = new MigrateRollbackCommand($migrator, fn () => $migrations);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('app:20260317_create_rollback', $tester->getDisplay());
        $this->assertStringContainsString('Rolled back 1 migration', $tester->getDisplay());
    }

    #[Test]
    public function reportsNothingToRollBack(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $command = new MigrateRollbackCommand($migrator, fn () => []);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to roll back', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter MigrateRollbackCommandTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement MigrateRollbackCommand**

Create `packages/cli/src/Command/MigrateRollbackCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Migration\Migrator;

#[AsCommand(
    name: 'migrate:rollback',
    description: 'Roll back the last batch of migrations',
)]
final class MigrateRollbackCommand extends Command
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
    ) {
        $this->migrationsProvider = $migrationsProvider;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrations = ($this->migrationsProvider)();
        $result = $this->migrator->rollback($migrations);

        if ($result->count === 0) {
            $output->writeln('Nothing to roll back.');
            return self::SUCCESS;
        }

        foreach ($result->migrations as $name) {
            $output->writeln("  Rolled back: {$name}");
        }

        $label = $result->count === 1 ? 'migration' : 'migrations';
        $output->writeln("Rolled back {$result->count} {$label}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter MigrateRollbackCommandTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/cli/src/Command/MigrateRollbackCommand.php packages/cli/tests/Unit/Command/MigrateRollbackCommandTest.php
git commit -m "feat: add migrate:rollback CLI command"
```

---

### Task 8: MigrateStatusCommand

**Files:**
- Create: `packages/cli/src/Command/MigrateStatusCommand.php`
- Create: `packages/cli/tests/Unit/Command/MigrateStatusCommandTest.php`

- [ ] **Step 1: Write the test**

Create `packages/cli/tests/Unit/Command/MigrateStatusCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\MigrateStatusCommand;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateStatusCommand::class)]
final class MigrateStatusCommandTest extends TestCase
{
    #[Test]
    public function showsPendingAndCompletedMigrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $ran = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        $pending = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };

        $migrations = ['app' => [
            'app:20260317_first' => $ran,
            'app:20260318_second' => $pending,
        ]];

        // Run only the first migration
        $migrator->run(['app' => ['app:20260317_first' => $ran]]);

        $command = new MigrateStatusCommand($migrator, fn () => $migrations);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('app:20260317_first', $display);
        $this->assertStringContainsString('Ran', $display);
        $this->assertStringContainsString('app:20260318_second', $display);
        $this->assertStringContainsString('Pending', $display);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter MigrateStatusCommandTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement MigrateStatusCommand**

Create `packages/cli/src/Command/MigrateStatusCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Migration\Migrator;

#[AsCommand(
    name: 'migrate:status',
    description: 'Show the status of each migration',
)]
final class MigrateStatusCommand extends Command
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
    ) {
        $this->migrationsProvider = $migrationsProvider;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrations = ($this->migrationsProvider)();
        $status = $this->migrator->status($migrations);

        $completedByName = [];
        foreach ($status['completed'] as $entry) {
            $completedByName[$entry['migration']] = $entry;
        }

        $rows = [];
        foreach ($status['completed'] as $entry) {
            $rows[] = [$entry['migration'], $entry['package'], 'Ran', (string) $entry['batch']];
        }
        foreach ($status['pending'] as $name) {
            $package = str_contains($name, ':') ? substr($name, 0, (int) strpos($name, ':')) : 'unknown';
            $rows[] = [$name, $package, 'Pending', ''];
        }

        $table = new Table($output);
        $table->setHeaders(['Migration', 'Package', 'Status', 'Batch']);
        $table->setRows($rows);
        $table->render();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter MigrateStatusCommandTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/cli/src/Command/MigrateStatusCommand.php packages/cli/tests/Unit/Command/MigrateStatusCommandTest.php
git commit -m "feat: add migrate:status CLI command"
```

---

### Task 9: Update make:migration to write files

**Files:**
- Modify: `packages/cli/src/Command/Make/MakeMigrationCommand.php`
- Modify: `packages/cli/tests/Unit/Command/Make/MakeMigrationCommandTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MakeMigrationCommandTest.php`:

```php
#[Test]
public function writesFileToMigrationsDirectory(): void
{
    $tempDir = sys_get_temp_dir() . '/waaseyaa_make_mig_test_' . uniqid();
    mkdir($tempDir, 0777, true);

    $command = new MakeMigrationCommand($tempDir);
    $tester = new CommandTester($command);
    $tester->execute(['name' => 'create_posts_table', '--create' => 'posts']);

    $migrationsDir = $tempDir . '/migrations';
    $this->assertDirectoryExists($migrationsDir);

    $files = glob($migrationsDir . '/*.php');
    $this->assertCount(1, $files);
    $this->assertStringContainsString('create_posts_table', $files[0]);

    $content = file_get_contents($files[0]);
    $this->assertStringContainsString('posts', $content);
    $this->assertStringContainsString('extends Migration', $content);

    // Cleanup
    array_map('unlink', glob($migrationsDir . '/*'));
    rmdir($migrationsDir);
    rmdir($tempDir);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter writesFileToMigrationsDirectory`
Expected: FAIL — command still prints to stdout, doesn't write files.

- [ ] **Step 3: Update MakeMigrationCommand**

Modify `MakeMigrationCommand` to accept a `$projectRoot` constructor parameter and write the file:

```php
public function __construct(
    private readonly string $projectRoot,
) {
    parent::__construct();
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $name = $input->getArgument('name');
    $createTable = $input->getOption('create');
    $modifyTable = $input->getOption('table');

    $table = $createTable ?? $modifyTable ?? $this->guessTableName($name);

    $rendered = $this->renderStub('migration', [
        'table' => $table,
    ]);

    $timestamp = date('Ymd_His');
    $filename = "{$timestamp}_{$name}.php";

    $targetDir = $this->projectRoot . '/migrations';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $targetPath = $targetDir . '/' . $filename;
    file_put_contents($targetPath, $rendered);

    $output->writeln("Created: migrations/{$filename}");

    return self::SUCCESS;
}
```

Note: The `--package` option support (writing to the package's migration directory) is deferred — it requires manifest access which `MakeMigrationCommand` doesn't currently have. The app-level path covers the primary use case.

- [ ] **Step 4: Update existing MakeMigrationCommandTest tests**

The existing tests instantiate `new MakeMigrationCommand()` with no args and check stdout output. Update them:
- Pass a temp directory as `$projectRoot` to the constructor
- Change assertions from checking stdout content to checking the written file exists and contains correct content
- Use `setUp()`/`tearDown()` to create and clean up the temp directory

- [ ] **Step 5: Update ConsoleKernel to pass $projectRoot**

In `packages/foundation/src/Kernel/ConsoleKernel.php`, change line 170:

```php
// Old:
new MakeMigrationCommand(),

// New:
new MakeMigrationCommand($this->projectRoot),
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit --filter MakeMigrationCommand`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/cli/src/Command/Make/MakeMigrationCommand.php packages/cli/tests/Unit/Command/Make/MakeMigrationCommandTest.php
git commit -m "feat: make:migration writes file to migrations/ directory"
```

---

### Task 10: Integration test — full round-trip

**Files:**
- Create: `tests/Integration/Migration/MigrationRoundTripTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class MigrationRoundTripTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_migration_rt_' . uniqid();
        mkdir($this->tempDir . '/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function fullRoundTrip(): void
    {
        // 1. Write a migration file
        file_put_contents($this->tempDir . '/migrations/20260317_143000_create_articles.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Waaseyaa\Foundation\Migration\Migration;
        use Waaseyaa\Foundation\Migration\SchemaBuilder;
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('articles', function ($table) {
                    $table->id();
                    $table->string('title', 255);
                    $table->text('body')->nullable();
                    $table->timestamps();
                });
            }
            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('articles');
            }
        };
        PHP);

        // 2. Set up infrastructure
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);
        $loader = new MigrationLoader($this->tempDir, new PackageManifest());

        // 3. Load and run migrations
        $migrations = $loader->loadAll();
        $this->assertCount(1, $migrations['app']);

        $result = $migrator->run($migrations);
        $this->assertSame(1, $result->count);

        // 4. Verify table was created
        $schema = new SchemaBuilder($connection);
        $this->assertTrue($schema->hasTable('articles'));
        $this->assertTrue($schema->hasColumn('articles', 'title'));

        // 5. Verify status shows completed
        $status = $migrator->status($migrations);
        $this->assertSame([], $status['pending']);
        $this->assertCount(1, $status['completed']);

        // 6. Run again — nothing to migrate
        $result2 = $migrator->run($migrations);
        $this->assertSame(0, $result2->count);

        // 7. Rollback
        $rollbackResult = $migrator->rollback($migrations);
        $this->assertSame(1, $rollbackResult->count);

        // 8. Verify table was dropped
        $this->assertFalse($schema->hasTable('articles'));

        // 9. Verify status shows pending
        $status2 = $migrator->status($migrations);
        $this->assertCount(1, $status2['pending']);
        $this->assertSame([], $status2['completed']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run the integration test**

Run: `./vendor/bin/phpunit tests/Integration/Migration/MigrationRoundTripTest.php`
Expected: PASS — full cycle works end to end.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Migration/MigrationRoundTripTest.php
git commit -m "test: add migration round-trip integration test"
```

---

### Task 11: Wire migration CLI commands in ConsoleKernel

**Files:**
- Modify: `packages/foundation/src/Kernel/ConsoleKernel.php`

Commands are manually instantiated in `ConsoleKernel::handle()` (not discovered via composer.json). The three migration commands need the `Migrator` and a `\Closure` that calls `MigrationLoader::loadAll()`.

- [ ] **Step 1: Add imports to ConsoleKernel**

```php
use Waaseyaa\CLI\Command\MigrateCommand;
use Waaseyaa\CLI\Command\MigrateRollbackCommand;
use Waaseyaa\CLI\Command\MigrateStatusCommand;
```

- [ ] **Step 2: Register migration commands in the $app->registerCommands() block**

Add after the existing command registrations (around line 216), before the providers loop:

```php
$migrationsProvider = fn () => $this->migrationLoader->loadAll();
```

Then add to the `registerCommands()` array:

```php
new MigrateCommand($this->migrator, $migrationsProvider),
new MigrateRollbackCommand($this->migrator, $migrationsProvider),
new MigrateStatusCommand($this->migrator, $migrationsProvider),
```

- [ ] **Step 3: Run all tests to verify no regressions**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add packages/foundation/src/Kernel/ConsoleKernel.php
git commit -m "feat: wire migrate, migrate:rollback, migrate:status commands in ConsoleKernel"
```
