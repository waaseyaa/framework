<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;

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
}
