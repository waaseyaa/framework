<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class SchemaAuthorityConcurrencyTest extends TestCase
{
    #[Test]
    public function two_processes_produce_one_applied_schema_result(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa-schema-authority-');
        self::assertIsString($databasePath);
        $startPath = $databasePath . '.start';
        $errorPaths = [$databasePath . '.error-0', $databasePath . '.error-1'];

        try {
            $setup = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
            $setup->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
            $setup->close();

            $children = [];
            for ($worker = 0; $worker < 2; ++$worker) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');
                if ($pid === 0) {
                    while (!is_file($startPath)) {
                        usleep(1_000);
                    }
                    try {
                        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
                        $connection->executeStatement('PRAGMA busy_timeout = 5000');
                        $repository = new MigrationRepository($connection);
                        new Migrator(
                            $connection,
                            $repository,
                            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
                        )->run([], [self::migration()]);
                        $connection->close();
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($errorPaths[$worker], $exception::class . ': ' . $exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            file_put_contents($startPath, 'start');
            foreach ($children as $worker => $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(
                    pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                    is_file($errorPaths[$worker]) ? (string) file_get_contents($errorPaths[$worker]) : 'worker failed',
                );
            }

            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM waaseyaa_migrations WHERE migration = ?',
                ['waaseyaa/test:v2:add-archived-at'],
            ));
            self::assertSame(2, (int) $connection->fetchOne(
                'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
            ));
            self::assertContains('archived_at', array_column(
                $connection->executeQuery('PRAGMA table_info(widgets)')->fetchAllAssociative(),
                'name',
            ));
            $repository = new MigrationRepository($connection);
            $manifest = $repository->schemaAuthorityManifest();
            self::assertNotNull($manifest);
            self::assertSame($repository->currentLogicalSchemaFingerprint(), $manifest->schemaFingerprint);
            self::assertSame($repository->currentLedgerFingerprint(), $manifest->ledgerFingerprint);
            $connection->close();
        } finally {
            foreach ([$startPath, ...$errorPaths, $databasePath . '-wal', $databasePath . '-shm', $databasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function process_termination_before_commit_leaves_no_partial_schema_or_manifest(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa-schema-kill-');
        self::assertIsString($databasePath);

        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'pcntl_fork() failed.');
            if ($pid === 0) {
                $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
                $repository = new MigrationRepository($connection);
                new SchemaMutationCoordinator($connection, $repository)->execute(function () use ($connection): never {
                    $connection->executeStatement('CREATE TABLE must_not_survive (id INTEGER PRIMARY KEY)');
                    posix_kill((int) getmypid(), SIGKILL);
                    exit(99);
                });
                exit(98);
            }

            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertSame(SIGKILL, pcntl_wtermsig($status));

            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
            self::assertSame([], $connection->fetchFirstColumn(
                "SELECT name FROM sqlite_schema WHERE name IN (
                    'must_not_survive', 'waaseyaa_migrations', 'waaseyaa_schema_authority'
                ) ORDER BY name",
            ));
            $connection->close();
        } finally {
            foreach ([$databasePath . '-wal', $databasePath . '-shm', $databasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private static function migration(): MigrationInterfaceV2
    {
        return new class implements MigrationInterfaceV2 {
            public function migrationId(): string
            {
                return 'waaseyaa/test:v2:add-archived-at';
            }

            public function package(): string
            {
                return 'waaseyaa/test';
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package(),
                    dependencies: [],
                    root: new CompositeDiff([
                        new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
                    ]),
                );
            }
        };
    }
}
