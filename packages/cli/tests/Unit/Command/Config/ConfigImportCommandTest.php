<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Tests\Fixtures\VerifiedConfigBundleFixture;
use Waaseyaa\Config\Exception\ConfigImportFailedException;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\RefusingConfigImportPreflight;

#[CoversClass(ConfigImportCommand::class)]
final class ConfigImportCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_import_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function fresh_import_prints_imported_lines_and_zero_exit(): void
    {
        $this->seed(['role.admin' => [], 'role.member' => []]);
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('imported role.admin', $stdout);
        self::assertStringContainsString('imported role.member', $stdout);
        self::assertStringContainsString('2 created, 0 updated, 0 deleted, 0 failed, 0 unchanged.', $stdout);
    }

    #[Test]
    public function dry_run_marks_output_and_skips_apply(): void
    {
        $this->seed(['role.admin' => []]);
        $hookSpy = new class implements ConfigImportApplyHookInterface {
            public bool $applyCalled = false;

            public function apply(ConfigSyncFile $file): string
            {
                $this->applyCalled = true;

                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void {}
        };
        $tester = $this->makeTester(hook: $hookSpy);

        $tester->execute(['--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('[dry-run]', $stdout);
        self::assertStringContainsString('[dry-run] 0 created, 1 updated, 0 deleted, 0 failed, 0 unchanged.', $stdout);
        self::assertFalse($hookSpy->applyCalled, '--dry-run must never call the apply hook.');
    }

    #[Test]
    public function transactional_dry_run_prints_generation_and_plan_identities(): void
    {
        $this->seed(['role.admin' => []]);
        $activator = new class implements ConfigurationActivatorInterface {
            public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
            {
                throw new \LogicException('dry-run activated');
            }
            public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
            {
                throw new \LogicException('not used');
            }
            public function committedResult(string $requestId): ?ConfigurationActivationResult
            {
                return null;
            }
            public function currentToken(): ?ConfigurationActiveToken
            {
                return null;
            }
            public function readGeneration(ConfigurationActiveToken $token): iterable
            {
                return [];
            }
        };
        $tester = $this->makeTester(activator: $activator);

        $tester->execute(['--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        self::assertMatchesRegularExpression(
            '/\[dry-run\] generation [a-f0-9]{64}; plan [a-f0-9]{64}/',
            $tester->getStdout(),
        );
    }

    #[Test]
    public function failure_exits_one_and_writes_to_stderr(): void
    {
        $this->seed(['role.admin' => []]);
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw ConfigImportFailedException::applyFailed($file->ref(), 'db lock timeout');
            }

            public function delete(string $ref): void {}
        };
        $tester = $this->makeTester(hook: $hook);

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('role.admin', $tester->getStderr());
        self::assertStringContainsString('db lock timeout', $tester->getStderr());
        self::assertStringContainsString('1 failed', $tester->getStdout());
    }

    #[Test]
    public function no_dependency_check_flag_threads_through_to_importer(): void
    {
        // Cycle that would crash the resolver — only `--no-dependency-check` allows progress.
        $this->seed([
            'menu.main' => ['role.admin'],
            'role.admin' => ['menu.main'],
        ]);
        $tester = $this->makeTester();

        $tester->execute(['--no-dependency-check']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('imported menu.main', $tester->getStdout());
        self::assertStringContainsString('imported role.admin', $tester->getStdout());
    }

    #[Test]
    public function refusedPreflightReturnsAStableNonzeroDiagnostic(): void
    {
        $this->seed(['role.admin' => []]);
        $tester = $this->makeTester(preflight: new RefusingConfigImportPreflight());

        $tester->execute(['--dry-run']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('config:import preflight refused', $tester->getStderr());
        self::assertStringContainsString('CFG-02 activation', $tester->getStderr());
    }

    #[Test]
    public function expectedTokenOptionsRequireOnePositiveIntegerSequence(): void
    {
        $this->seed(['role.admin' => []]);
        $tester = $this->makeTester();

        $tester->execute([
            '--expected-generation=' . str_repeat('a', 64),
            '--expected-sequence=1.5',
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('must be supplied together', $tester->getStderr());
    }

    /**
     * @param array<string, list<string>> $refsWithDeps
     */
    private function seed(array $refsWithDeps): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($refsWithDeps as $ref => $dependencies) {
            [$entityType, $entityId] = explode('.', $ref, 2);
            $file = ConfigSyncFile::writable(
                entityType: $entityType,
                entityId: $entityId,
                uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
                dependencies: $dependencies,
                langcode: 'en',
                fields: [],
                schemaId: 'waaseyaa.test.config',
                schemaVersion: 1,
                schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ownerPackage: 'waaseyaa/config',
                ownerConfigContractVersion: 1,
            );
            $repository->put($file);
        }
    }

    private function makeTester(
        ?ConfigImportApplyHookInterface $hook = null,
        ?ConfigImportPreflightInterface $preflight = null,
        ?ConfigurationActivatorInterface $activator = null,
    ): CliTester {
        $repository = new ConfigSyncRepository($this->tempDir);
        $hook ??= new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                return ConfigImportEntryResult::STATUS_CREATED;
            }

            public function delete(string $ref): void {}
        };
        if ($preflight === null && $activator !== null) {
            $bundle = VerifiedConfigBundleFixture::fromFiles(array_values(iterator_to_array($repository->list())));
            $preflight = new class ($bundle) implements ConfigImportPreflightInterface {
                public function __construct(private readonly VerifiedConfigBundle $bundle) {}

                public function assertReady(
                    array $syncFiles,
                    array $activeRefs,
                    bool $dryRun,
                    bool $deleteOrphans,
                    bool $noDependencyCheck,
                ): ?VerifiedConfigBundle {
                    return $this->bundle;
                }
            };
        }
        $importer = new ConfigImporter(
            $repository,
            $hook,
            $preflight ?? new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
            activator: $activator,
        );
        $command = new ConfigImportCommand($importer);

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:import',
            description: 'Apply the sync store to the active store in DAG order (FR-022).',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Compute would-be writes without DB mutation.',
                ),
                new HandlerOption(
                    name: 'delete-orphans',
                    mode: HandlerOptionMode::None,
                    description: 'Delete active-store entities with no matching sync file.',
                ),
                new HandlerOption(
                    name: 'halt-on-error',
                    mode: HandlerOptionMode::None,
                    description: 'Stop after the first per-entity failure.',
                ),
                new HandlerOption(
                    name: 'no-dependency-check',
                    mode: HandlerOptionMode::None,
                    description: 'Emergency bypass: skip validation and DAG ordering.',
                ),
                new HandlerOption(
                    name: 'activation-request-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Stable idempotency identity.',
                ),
                new HandlerOption(
                    name: 'expected-generation',
                    mode: HandlerOptionMode::Required,
                    description: 'Expected active generation.',
                ),
                new HandlerOption(
                    name: 'expected-sequence',
                    mode: HandlerOptionMode::Required,
                    description: 'Expected active sequence.',
                ),
            ],
            handler: [ConfigImportCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigImportCommand $command): ContainerInterface
    {
        return new class ($command) implements ContainerInterface {
            public function __construct(private readonly ConfigImportCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigImportCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigImportCommand::class;
            }
        };
    }

}
