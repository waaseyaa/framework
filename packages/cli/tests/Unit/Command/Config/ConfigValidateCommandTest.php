<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\Config\ConfigValidateCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\Config\Sync\FieldViolation;

#[CoversClass(ConfigValidateCommand::class)]
final class ConfigValidateCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_validate_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function empty_sync_directory_exits_zero_with_friendly_message(): void
    {
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No sync-store files found', $tester->getStdout());
    }

    #[Test]
    public function all_valid_entries_render_ok_and_exit_zero(): void
    {
        // FR-040 — CI gate: every entity valid -> exit 0.
        $this->seed('role.admin', ['label' => 'Admin']);
        $this->seed('role.member', ['label' => 'Member']);
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('role.admin: OK', $stdout);
        self::assertStringContainsString('role.member: OK', $stdout);
    }

    #[Test]
    public function any_invalid_entry_exits_one_for_ci_gate(): void
    {
        // FR-040 — CI gate: any entity invalid -> exit 1.
        $this->seed('role.admin', ['label' => 'Admin']);
        $this->seed('role.broken', []); // empty fields trips the fallback required check.
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
    }

    #[Test]
    public function invalid_entries_render_per_field_violation_lines(): void
    {
        // FR-039 — output must be per-entity, with per-field detail.
        $this->seed('taxonomy_vocabulary.community_categories', [
            'description' => '',
            'weight' => -5,
        ]);
        $hook = static function (ConfigSyncFile $file): array {
            $violations = [];
            if (($file->fields['description'] ?? null) === '') {
                $violations[] = new FieldViolation(
                    field: 'description',
                    message: 'must be at least 1 character',
                    code: 'string.min_length',
                );
            }
            $weight = $file->fields['weight'] ?? null;
            if (\is_int($weight) && $weight < 0) {
                $violations[] = new FieldViolation(
                    field: 'weight',
                    message: 'must be non-negative',
                    code: 'integer.non_negative',
                );
            }

            return $violations;
        };
        $tester = $this->makeTester($hook);

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('taxonomy_vocabulary.community_categories:', $stdout);
        // Per-field violation detail lands on STDERR so CI logs surface it.
        $stderr = $tester->getStderr();
        self::assertStringContainsString("field 'description': must be at least 1 character", $stderr);
        self::assertStringContainsString("field 'weight': must be non-negative", $stderr);
    }

    #[Test]
    public function ci_gate_semantics_runnable_independently_without_other_options(): void
    {
        // FR-040 — `config:validate` MUST be runnable independently as a
        // deploy-time gate before `config:import`. Empty arg list, exit code
        // driven purely by validation outcome.
        $this->seed('role.admin', ['label' => 'Admin']);
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
    }

    #[Test]
    public function default_fallback_required_check_drives_exit_code_when_no_hook_wired(): void
    {
        // WP06 spec §10.1 fallback rule documented in the validator: with no
        // FieldDefinition::validators() hook supplied, empty fields fail.
        $this->seed('role.empty', []);
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
    }

    /**
     * @param (callable(ConfigSyncFile): list<FieldViolation>)|null $fieldValidationHook
     */
    private function makeTester(?callable $fieldValidationHook = null): CliTester
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $validator = new ConfigSyncValidator($repo, $fieldValidationHook);
        $command = new ConfigValidateCommand($validator);

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:validate',
            description: 'Validate every sync-store file against entity-type field constraints (FR-037).',
            options: [],
            handler: [ConfigValidateCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigValidateCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ConfigValidateCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigValidateCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigValidateCommand::class;
            }
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function seed(string $ref, array $fields): void
    {
        [$entityType, $entityId] = explode('.', $ref, 2);
        ksort($fields, \SORT_STRING);
        $file = new ConfigSyncFile(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
        );
        (new ConfigSyncRepository($this->tempDir))->put($file);
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
