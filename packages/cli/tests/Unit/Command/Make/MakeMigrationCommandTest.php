<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeMigrationHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Discovery\PackageManifest;

#[CoversClass(MakeMigrationHandler::class)]
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
        $tester->execute(['create_comments_table', '--create=comments']);

        $this->assertSame(0, $tester->getExitCode());
        $content = $this->getWrittenFileContent();
        $this->assertStringContainsString("schema->create('comments'", $content);
        $this->assertStringContainsString("schema->dropIfExists('comments')", $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
    }

    #[Test]
    public function it_guesses_table_name_from_migration_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['create_users_table']);

        $content = $this->getWrittenFileContent();
        $this->assertStringContainsString("'users'", $content);
    }

    #[Test]
    public function it_includes_filename_in_output(): void
    {
        $tester = $this->createTester();
        $tester->execute(['create_nodes_table', '--create=nodes']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('Created: migrations/', $output);
        $this->assertStringContainsString('create_nodes_table', $output);
    }

    #[Test]
    public function writesFileToMigrationsDirectory(): void
    {
        $tester = $this->createTester();
        $tester->execute(['create_posts_table', '--create=posts']);

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
            migrations: ['waaseyaa/node' => $packageMigDir],
            fieldTypes: [],
            middleware: [],
        );

        $tester = $this->createTester($manifest);
        $tester->execute(['add_body_field', '--table=node', '--package=waaseyaa/node']);

        $this->assertSame(0, $tester->getExitCode());
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
            migrations: [],
            fieldTypes: [],
            middleware: [],
        );

        $tester = $this->createTester($manifest);
        $tester->execute(['test_migration', '--package=waaseyaa/nonexistent']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('no registered migration directory', $tester->getStderr());
    }

    #[Test]
    public function it_fails_when_package_flag_used_without_manifest(): void
    {
        $tester = $this->createTester();
        $tester->execute(['test_migration', '--package=waaseyaa/node']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('PackageManifest not available', $tester->getStderr());
    }

    private function createTester(?PackageManifest $manifest = null): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:migration') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $tempDir = $this->tempDir;
        $container = new class ($tempDir, $manifest) implements ContainerInterface {
            public function __construct(
                private readonly string $projectRoot,
                private readonly ?PackageManifest $manifest,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === MakeMigrationHandler::class) {
                    return new MakeMigrationHandler($this->projectRoot, $this->manifest);
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeMigrationHandler::class;
            }
        };

        return CliTester::for($definition, $container);
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
            $item_path = $dir . '/' . $item;
            is_dir($item_path) ? $this->removeDir($item_path) : unlink($item_path);
        }
        rmdir($dir);
    }
}
