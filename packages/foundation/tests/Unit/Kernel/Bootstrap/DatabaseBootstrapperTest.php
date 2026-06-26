<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(DatabaseBootstrapper::class)]
final class DatabaseBootstrapperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($this->tempDir, 0o755, recursive: true);
        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('WAASEYAA_DB');

        // Clean up temp files recursively.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function bootCreatesStorageDirectoryWhenMissing(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);

        // storage/ does not exist yet
        $this->assertDirectoryDoesNotExist($projectRoot . '/storage');

        $bootstrapper = new DatabaseBootstrapper();
        $database = $bootstrapper->boot($projectRoot, []);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
        $this->assertDirectoryExists($projectRoot . '/storage');
    }

    #[Test]
    public function bootUsesConfigDatabasePathWhenProvided(): void
    {
        $dbPath = $this->tempDir . '/custom/my.sqlite';

        // custom/ does not exist yet
        $this->assertDirectoryDoesNotExist($this->tempDir . '/custom');

        $bootstrapper = new DatabaseBootstrapper();
        $database = $bootstrapper->boot($this->tempDir, ['database' => $dbPath]);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
        $this->assertDirectoryExists($this->tempDir . '/custom');
        $this->assertFileExists($dbPath);
    }

    #[Test]
    public function bootUsesEnvVarOverDefault(): void
    {
        $dbPath = $this->tempDir . '/envdir/env.sqlite';
        putenv('WAASEYAA_DB=' . $dbPath);

        try {
            $bootstrapper = new DatabaseBootstrapper();
            $database = $bootstrapper->boot($this->tempDir, []);

            $this->assertInstanceOf(DatabaseInterface::class, $database);
            $this->assertDirectoryExists($this->tempDir . '/envdir');
            $this->assertFileExists($dbPath);
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    #[Test]
    public function bootDefaultsToStorageWaaseyaaSqlite(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);

        // Ensure no env var interference.
        putenv('WAASEYAA_DB');

        $bootstrapper = new DatabaseBootstrapper();
        $bootstrapper->boot($projectRoot, []);

        // The default path creates storage/ under project root.
        $this->assertDirectoryExists($projectRoot . '/storage');
        $this->assertFileExists($projectRoot . '/storage/waaseyaa.sqlite');
    }

    #[Test]
    public function bootRefusesMissingSqliteDatabaseInProduction(): void
    {
        $dbPath = $this->tempDir . '/missing/prod.sqlite';
        $parentDir = dirname($dbPath);

        $bootstrapper = new DatabaseBootstrapper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf('Database not found at %s. In production, the database must already exist.', $dbPath),
        );

        try {
            $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'production']);
        } finally {
            $this->assertDirectoryDoesNotExist($parentDir);
        }
    }

    #[Test]
    public function bootAllowsMissingSqliteDatabaseOutsideProduction(): void
    {
        $dbPath = $this->tempDir . '/dev/dev.sqlite';

        $bootstrapper = new DatabaseBootstrapper();
        $database = $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'local']);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
        $this->assertFileExists($dbPath);
    }

    #[Test]
    public function bootAllowsExistingSqliteDatabaseInProduction(): void
    {
        $dbPath = $this->tempDir . '/prod/existing.sqlite';
        mkdir(dirname($dbPath), 0o755, true);
        touch($dbPath);

        $bootstrapper = new DatabaseBootstrapper();
        $database = $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'production']);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
        $this->assertFileExists($dbPath);
    }

    // ----- Mission request-surface-hardening (#1650) WP02: resolution matrix -----
    // Contract `bearer-and-dbpath.md` §9–13 / data-model.md path resolution
    // matrix. resolveDatabasePath() is a pure function of (configured value,
    // projectRoot) — process CWD never participates.

    #[Test]
    public function relativeConfigValueResolvesAgainstProjectRoot(): void
    {
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => 'storage/custom.sqlite']);

        $this->assertSame('/proj/storage/custom.sqlite', $resolved);
    }

    #[Test]
    public function relativeEnvValueResolvesAgainstProjectRoot(): void
    {
        putenv('WAASEYAA_DB=storage/env.sqlite');

        try {
            $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', []);
            $this->assertSame('/proj/storage/env.sqlite', $resolved);
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    #[Test]
    public function dotSlashPrefixIsStrippedBeforeJoining(): void
    {
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => './storage/dotted.sqlite']);

        $this->assertSame('/proj/storage/dotted.sqlite', $resolved);
    }

    #[Test]
    public function climbingRelativeConcatenatesOntoProjectRoot(): void
    {
        // Contract §12: climbing values resolve against the project root —
        // concatenated, not collapsed.
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => '../shared/db.sqlite']);

        $this->assertSame('/proj/../shared/db.sqlite', $resolved);
    }

    #[Test]
    public function leadingSlashAbsoluteValuePassesThroughUntouched(): void
    {
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => '/var/data/db.sqlite']);

        $this->assertSame('/var/data/db.sqlite', $resolved);
    }

    #[Test]
    public function driveLetterAbsoluteValuesPassThroughUntouched(): void
    {
        $backslash = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => 'C:\\data\\db.sqlite']);
        $forwardSlash = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => 'c:/data/db.sqlite']);

        $this->assertSame('C:\\data\\db.sqlite', $backslash);
        $this->assertSame('c:/data/db.sqlite', $forwardSlash);
    }

    #[Test]
    public function uncPathPassesThroughUntouched(): void
    {
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => '\\\\server\\share\\db.sqlite']);

        $this->assertSame('\\\\server\\share\\db.sqlite', $resolved);
    }

    #[Test]
    public function memorySentinelPassesThroughUntouched(): void
    {
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => ':memory:']);

        $this->assertSame(':memory:', $resolved);
    }

    #[Test]
    public function unsetDefaultIsByteIdenticalToPreMissionConcatenation(): void
    {
        // Acceptance scenario 5: the default is the same concatenation as
        // before the mission, not routed through new logic.
        $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', []);

        $this->assertSame('/proj/storage/waaseyaa.sqlite', $resolved);
    }

    #[Test]
    public function configValueTakesPrecedenceOverEnv(): void
    {
        putenv('WAASEYAA_DB=storage/env.sqlite');

        try {
            $resolved = DatabaseBootstrapper::resolveDatabasePath('/proj', ['database' => 'storage/cfg.sqlite']);
            $this->assertSame('/proj/storage/cfg.sqlite', $resolved);
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    #[Test]
    public function bootResolvesRelativeEnvValueUnderProjectRoot(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);
        putenv('WAASEYAA_DB=storage/rel-env.sqlite');

        try {
            $database = new DatabaseBootstrapper()->boot($projectRoot, []);

            $this->assertInstanceOf(DatabaseInterface::class, $database);
            $this->assertFileExists($projectRoot . '/storage/rel-env.sqlite');
            // CWD-relative location (the pre-fix behavior) must NOT be used.
            $this->assertFileDoesNotExist((getcwd() ?: '.') . '/storage/rel-env.sqlite');
        } finally {
            putenv('WAASEYAA_DB');
        }
    }

    #[Test]
    public function bootResolvesRelativeConfigValueUnderProjectRoot(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);

        $database = new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'storage/rel-cfg.sqlite']);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
        $this->assertFileExists($projectRoot . '/storage/rel-cfg.sqlite');
        $this->assertFileDoesNotExist((getcwd() ?: '.') . '/storage/rel-cfg.sqlite');
    }

    // ----- Mission request-surface-hardening (#1650) WP02: docroot warning -----
    // Contract §17–19 / FR-008.

    #[Test]
    public function bootWarnsOnceWhenResolvedPathIsInsideDocroot(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);
        $spy = $this->spyLogger();

        new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'public/oops.sqlite'], $spy);

        $this->assertCount(1, $spy->warnings);
        $this->assertStringContainsString('public', $spy->warnings[0]);
        $this->assertStringContainsString('WAASEYAA_DB', $spy->warnings[0]);
    }

    #[Test]
    public function bootWarnsForWindowsSeparatedRelativeInsideDocroot(): void
    {
        // The containment normalizer is pure string logic — backslash forms
        // must be detected on every platform.
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot . '/public', 0o755, recursive: true);
        $spy = $this->spyLogger();

        new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'public\\win.sqlite'], $spy);

        $this->assertCount(1, $spy->warnings);
    }

    #[Test]
    public function bootDoesNotWarnForPathOutsideDocroot(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);
        $spy = $this->spyLogger();

        new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'storage/fine.sqlite'], $spy);

        $this->assertSame([], $spy->warnings);
    }

    #[Test]
    public function bootDoesNotWarnForPathClimbingBackOutOfDocroot(): void
    {
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);
        $spy = $this->spyLogger();

        new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'public/../storage/safe.sqlite'], $spy);

        $this->assertSame([], $spy->warnings);
    }

    #[Test]
    public function bootDoesNotWarnForMemoryDatabase(): void
    {
        $spy = $this->spyLogger();

        new DatabaseBootstrapper()->boot($this->tempDir, ['database' => ':memory:'], $spy);

        $this->assertSame([], $spy->warnings);
    }

    #[Test]
    public function bootWithoutLoggerProceedsSilentlyForDocrootPath(): void
    {
        // Contract §19: a kernel constructed without a logger boots silently;
        // the advisory never throws.
        $projectRoot = $this->tempDir . '/project';
        mkdir($projectRoot, 0o755, recursive: true);

        $database = new DatabaseBootstrapper()->boot($projectRoot, ['database' => 'public/oops.sqlite']);

        $this->assertInstanceOf(DatabaseInterface::class, $database);
    }

    #[Test]
    public function bootThrowsWhenParentDirectoryCannotBeCreated(): void
    {
        // Simulate an uncreatable parent by placing the database path under a
        // regular FILE — mkdir will fail with ENOTDIR because a path component
        // is not a directory. This works even as root (it is not a permission
        // trick; the filesystem rejects the operation structurally).
        $file = tempnam(sys_get_temp_dir(), 'wsdb');
        if ($file === false) {
            self::markTestSkipped('tempnam() failed — cannot proceed');
        }

        try {
            // $file is a regular file; asking for $file/sub/db.sqlite means
            // dirname() is $file/sub, which cannot be mkdir'd.
            $dbPath = $file . '/sub/db.sqlite';

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to create the database directory/');

            // Suppress the E_WARNING that mkdir() emits on ENOTDIR — this is the
            // OS-level signal that mkdir failed; our production code converts it to
            // a RuntimeException, which is what this test asserts.
            set_error_handler(static fn() => true, \E_WARNING);
            try {
                new DatabaseBootstrapper()->boot(
                    sys_get_temp_dir(),
                    ['database' => $dbPath, 'environment' => 'dev'],
                );
            } finally {
                restore_error_handler();
            }
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return LoggerInterface&object{warnings: list<string>}
     */
    private function spyLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $warnings = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
    }
}
