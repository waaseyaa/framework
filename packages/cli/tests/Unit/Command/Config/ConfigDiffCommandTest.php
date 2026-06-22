<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Config\ConfigDiffCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigDiffCommand::class)]
final class ConfigDiffCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_diff_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function clean_state_exits_zero_with_no_output(): void
    {
        $file = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $tester = $this->makeTester([$file], [$file]);

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), 'in-sync state must exit 0 (FR-032).');
        self::assertSame('', $tester->getStdout());
    }

    #[Test]
    public function drift_exits_one_and_renders_unified_diff(): void
    {
        $sync = $this->makeFile('role', 'admin', ['label' => 'Admin (sync)']);
        $active = $this->makeFile('role', 'admin', ['label' => 'Admin (active)']);
        $tester = $this->makeTester([$sync], [$active]);

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), 'drift must exit 1 (FR-032).');
        $stdout = $tester->getStdout();
        self::assertStringContainsString('--- a/role.admin', $stdout);
        self::assertStringContainsString('+++ b/role.admin', $stdout);
    }

    #[Test]
    public function uuid_rename_renders_annotation_line(): void
    {
        $uuid = ConfigSyncFile::deterministicUuid('role', 'coordinator');
        $sync = new ConfigSyncFile(
            entityType: 'role',
            entityId: 'community_coordinator',
            uuid: $uuid,
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'CC'],
        );
        $active = new ConfigSyncFile(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: $uuid,
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'C'],
        );

        $tester = $this->makeTester([$sync], [$active]);

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString(
            '=== renamed: role.coordinator → role.community_coordinator (uuid:',
            $stdout,
        );
    }

    #[Test]
    public function scoped_to_unknown_ref_exits_one_with_stderr(): void
    {
        $tester = $this->makeTester([], []);

        $tester->execute(['role.nonexistent']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('role.nonexistent', $tester->getStderr());
    }

    #[Test]
    public function scoped_to_in_sync_ref_exits_zero(): void
    {
        $file = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $tester = $this->makeTester([$file], [$file]);

        $tester->execute(['role.admin']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame('', $tester->getStdout());
    }

    /**
     * @param list<ConfigSyncFile> $syncFiles
     * @param list<ConfigSyncFile> $activeFiles
     */
    private function makeTester(array $syncFiles, array $activeFiles): CliTester
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        foreach ($syncFiles as $file) {
            $repo->put($file);
        }
        $source = new class($activeFiles) implements ConfigSyncFileSourceInterface {
            /** @param list<ConfigSyncFile> $files */
            public function __construct(private readonly array $files) {}

            public function iterate(): iterable
            {
                yield from $this->files;
            }
        };
        $command = new ConfigDiffCommand(new ConfigDiffer($repo, $source));

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:diff',
            description: 'Show unified diffs between sync and active stores (FR-030).',
            arguments: [
                new HandlerArgument(
                    name: 'ref',
                    mode: HandlerArgumentMode::Optional,
                    description: 'Scope diff to <entity-type>.<entity-id>.',
                ),
            ],
            handler: [ConfigDiffCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigDiffCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ConfigDiffCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigDiffCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigDiffCommand::class;
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
