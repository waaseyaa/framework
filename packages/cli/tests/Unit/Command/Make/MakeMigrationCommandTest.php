<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use Waaseyaa\CLI\Command\Make\MakeMigrationCommand;
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
