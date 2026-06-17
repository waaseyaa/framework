<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigExportCommand::class)]
final class ConfigExportCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_export_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function fresh_export_prints_created_lines_and_zero_exit(): void
    {
        $tester = $this->makeTester(
            files: [
                $this->makeFile('role', 'admin', ['label' => 'Admin']),
                $this->makeFile('role', 'member', ['label' => 'Member']),
            ],
        );

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('created role.admin.yml', $stdout);
        self::assertStringContainsString('created role.member.yml', $stdout);
        self::assertStringContainsString('2 created, 0 updated, 0 unchanged.', $stdout);
    }

    #[Test]
    public function dry_run_does_not_write_files_and_marks_output(): void
    {
        $tester = $this->makeTester(
            files: [$this->makeFile('role', 'admin', ['label' => 'Admin'])],
        );

        $tester->execute(['--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('[dry-run] created role.admin.yml', $stdout);
        self::assertStringContainsString('[dry-run] 1 created, 0 updated, 0 unchanged.', $stdout);
        self::assertFileDoesNotExist($this->tempDir . '/role.admin.yml');
    }

    #[Test]
    public function summary_line_matches_canonical_format(): void
    {
        // Seed an existing file so we exercise more than one outcome category.
        $repository = new ConfigSyncRepository($this->tempDir);
        $repository->put($this->makeFile('role', 'admin', ['label' => 'Admin']));

        $tester = $this->makeTester(
            files: [
                // Brand-new -> created
                $this->makeFile('role', 'coordinator', ['label' => 'Coord']),
                // Identical -> unchanged
                $this->makeFile('role', 'admin', ['label' => 'Admin']),
            ],
            repository: $repository,
        );

        $tester->execute(['--diff']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString(
            '1 created, 0 updated, 1 unchanged.',
            $tester->getStdout(),
        );
    }

    #[Test]
    public function serialization_failure_exits_one_with_stderr_message(): void
    {
        $source = new class implements ConfigSyncFileSourceInterface {
            public function iterate(): iterable
            {
                yield from [];
                throw ConfigSerializationException::typeMismatch('label', 'string', 'array');
            }
        };

        $repository = new ConfigSyncRepository($this->tempDir);
        $command = new ConfigExportCommand(new ConfigExporter($source, $repository));
        $tester = CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('config:export', $tester->getStderr());
    }

    /**
     * @param list<ConfigSyncFile> $files
     */
    private function makeTester(array $files, ?ConfigSyncRepository $repository = null): CliTester
    {
        $repository ??= new ConfigSyncRepository($this->tempDir);
        $source = new class($files) implements ConfigSyncFileSourceInterface {
            /** @param list<ConfigSyncFile> $files */
            public function __construct(private readonly array $files) {}

            public function iterate(): iterable
            {
                yield from $this->files;
            }
        };
        $command = new ConfigExportCommand(new ConfigExporter($source, $repository));

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:export',
            description: 'Write the active store out to the sync store (FR-017).',
            options: [
                new HandlerOption(
                    name: 'diff',
                    mode: HandlerOptionMode::None,
                    description: 'Write only when YAML differs (preserves git mtime semantics).',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Compute would-be writes without filesystem effects.',
                ),
            ],
            handler: [ConfigExportCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigExportCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ConfigExportCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigExportCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigExportCommand::class;
            }
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeFile(string $entityType, string $entityId, array $fields): ConfigSyncFile
    {
        ksort($fields, \SORT_STRING);

        return new ConfigSyncFile(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
