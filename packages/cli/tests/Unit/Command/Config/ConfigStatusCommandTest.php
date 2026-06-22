<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\Config\ConfigStatusCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigStatusCommand::class)]
final class ConfigStatusCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_status_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function plain_format_prints_counts_line(): void
    {
        $file = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $tester = $this->makeTester([$file], [$file]);

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), 'status is always exit 0 (FR-036).');
        $stdout = $tester->getStdout();
        self::assertStringContainsString(
            '1 in-sync, 0 drift, 0 sync-only, 0 active-only, 0 renamed.',
            $stdout,
        );
    }

    #[Test]
    public function plain_format_renders_per_entity_table_under_threshold(): void
    {
        $admin = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $member = $this->makeFile('role', 'member', ['label' => 'Member']);
        $tester = $this->makeTester([$admin, $member], [$admin, $member]);

        $tester->execute([]);

        $stdout = $tester->getStdout();
        self::assertStringContainsString('[role]', $stdout);
        self::assertStringContainsString('role.admin — in_sync', $stdout);
        self::assertStringContainsString('role.member — in_sync', $stdout);
    }

    #[Test]
    public function json_format_emits_documented_payload_shape(): void
    {
        $admin = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $tester = $this->makeTester([$admin], [$admin]);

        $tester->execute(['--format=json']);

        self::assertSame(0, $tester->getExitCode());
        $payload = json_decode($tester->getStdout(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('counts', $payload);
        self::assertArrayHasKey('entries', $payload);
        self::assertSame([
            'in_sync' => 1,
            'drift' => 0,
            'sync_only' => 0,
            'active_only' => 0,
            'renamed' => 0,
        ], $payload['counts']);
        self::assertSame([['ref' => 'role.admin', 'status' => 'in_sync']], $payload['entries']);
    }

    #[Test]
    public function json_format_includes_renamed_from_for_renames(): void
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

        $tester->execute(['--format=json']);

        $payload = json_decode($tester->getStdout(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['counts']['renamed']);
        self::assertSame('role.community_coordinator', $payload['entries'][0]['ref']);
        self::assertSame('renamed', $payload['entries'][0]['status']);
        self::assertSame('role.coordinator', $payload['entries'][0]['renamed_from']);
    }

    #[Test]
    public function invalid_format_exits_one_with_stderr_message(): void
    {
        $tester = $this->makeTester([], []);

        $tester->execute(['--format=xml']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('--format', $tester->getStderr());
    }

    #[Test]
    public function status_with_drift_still_exits_zero(): void
    {
        // FR-036 / contracts: status is informational; use config:diff for gating.
        $sync = $this->makeFile('role', 'admin', ['label' => 'Sync']);
        $active = $this->makeFile('role', 'admin', ['label' => 'Active']);
        $tester = $this->makeTester([$sync], [$active]);

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('1 drift', $tester->getStdout());
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
        $command = new ConfigStatusCommand(new ConfigStatusReporter(new ConfigDiffer($repo, $source)));

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:status',
            description: 'Summarise drift between sync and active stores (FR-034).',
            options: [
                new HandlerOption(
                    name: 'format',
                    mode: HandlerOptionMode::Required,
                    description: 'Output format: plain (default) or json.',
                    default: 'plain',
                ),
            ],
            handler: [ConfigStatusCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigStatusCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ConfigStatusCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigStatusCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigStatusCommand::class;
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
