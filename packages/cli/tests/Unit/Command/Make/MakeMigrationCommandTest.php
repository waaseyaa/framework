<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use Waaseyaa\CLI\Command\Make\MakeMigrationCommand;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeMigrationCommand::class)]
final class MakeMigrationCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_make_mig_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function it_generates_a_migration_with_create_table(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'create_comments_table',
            '--create' => 'comments',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $content = $this->getWrittenFileContent();
        $this->assertStringContainsString("schema->create('comments'", $content);
        $this->assertStringContainsString("schema->dropIfExists('comments')", $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
    }

    #[Test]
    public function it_guesses_table_name_from_migration_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'create_users_table']);

        $content = $this->getWrittenFileContent();
        $this->assertStringContainsString("'users'", $content);
    }

    #[Test]
    public function it_includes_filename_in_output(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'create_nodes_table',
            '--create' => 'nodes',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Created: migrations/', $output);
        $this->assertStringContainsString('create_nodes_table', $output);
    }

    #[Test]
    public function writesFileToMigrationsDirectory(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'create_posts_table', '--create' => 'posts']);

        $migrationsDir = $this->tempDir . '/migrations';
        $this->assertDirectoryExists($migrationsDir);

        $files = glob($migrationsDir . '/*.php');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('create_posts_table', $files[0]);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('posts', $content);
        $this->assertStringContainsString('extends Migration', $content);
    }

    #[Test]
    public function it_writes_to_package_migration_directory(): void
    {
        $packageMigDir = $this->tempDir . '/packages/node/migrations';
        $manifest = new PackageManifest(
            providers: [],
            commands: [],
            routes: [],
            migrations: ['waaseyaa/node' => $packageMigDir],
            fieldTypes: [],
            middleware: [],
        );

        $command = new MakeMigrationCommand($this->tempDir, $manifest);
        $tester = new CommandTester($command);
        $tester->execute([
            'name' => 'add_body_field',
            '--table' => 'node',
            '--package' => 'waaseyaa/node',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertDirectoryExists($packageMigDir);

        $files = glob($packageMigDir . '/*.php');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('add_body_field', $files[0]);
    }

    #[Test]
    public function it_fails_for_unknown_package(): void
    {
        $manifest = new PackageManifest(
            providers: [],
            commands: [],
            routes: [],
            migrations: [],
            fieldTypes: [],
            middleware: [],
        );

        $command = new MakeMigrationCommand($this->tempDir, $manifest);
        $tester = new CommandTester($command);
        $tester->execute([
            'name' => 'test_migration',
            '--package' => 'waaseyaa/nonexistent',
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no registered migration directory', $tester->getDisplay());
    }

    #[Test]
    public function it_fails_when_package_flag_used_without_manifest(): void
    {
        $command = new MakeMigrationCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([
            'name' => 'test_migration',
            '--package' => 'waaseyaa/node',
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('PackageManifest not available', $tester->getDisplay());
    }

    private function createTester(): CommandTester
    {
        $command = new MakeMigrationCommand($this->tempDir);
        return new CommandTester($command);
    }

    private function getWrittenFileContent(): string
    {
        $files = glob($this->tempDir . '/migrations/*.php');
        $this->assertNotEmpty($files, 'Expected a migration file to be written');
        return file_get_contents($files[0]);
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
