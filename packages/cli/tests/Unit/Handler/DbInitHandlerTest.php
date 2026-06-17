<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(DbInitHandler::class)]
final class DbInitHandlerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
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
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function freshVolumeCreatesDatabaseAndSucceeds(): void
    {
        $dbPath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $this->assertFileDoesNotExist($dbPath);

        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertFileExists($dbPath);
        $this->assertStringContainsString('Created database', $tester->getStdout());
        $this->assertStringContainsString('Database ready', $tester->getStdout());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $this->assertTrue($connection->createSchemaManager()->tablesExist(['waaseyaa_migrations']));
    }

    #[Test]
    public function rerunOnInitializedDatabaseIsIdempotent(): void
    {
        // First run.
        $this->createTester()->execute([]);

        // Second run.
        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Database already present', $tester->getStdout());
        $this->assertStringContainsString('No pending migrations', $tester->getStdout());
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
    public function dryRunOnInitializedDatabaseReportsPending(): void
    {
        $this->createTester()->execute([]);

        $tester = $this->createTester();
        $tester->executeMap(['--dry-run' => true]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('present and initialized', $tester->getStdout());
        $this->assertStringContainsString('No pending migrations', $tester->getStdout());
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
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
