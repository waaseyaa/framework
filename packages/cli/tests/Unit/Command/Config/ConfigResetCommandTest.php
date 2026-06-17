<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Config\ConfigResetCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Io\StdinSource;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Audit\ConfigAuditEvent;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigResetter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigResetCommand::class)]
final class ConfigResetCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_reset_cli_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function yes_flag_skips_prompt_and_applies_reset(): void
    {
        $this->seed(['role.admin']);
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        $tester = $this->makeTester($hookSpy);

        $tester->execute(['role.admin', '--yes']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame(['role.admin'], $hookSpy->applied);
        self::assertStringContainsString('reset role.admin', $tester->getStdout());
    }

    #[Test]
    public function non_interactive_without_yes_refuses_and_exits_one(): void
    {
        $this->seed(['role.admin']);
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        $tester = $this->makeTester($hookSpy);

        $tester->execute(['role.admin']);

        self::assertSame(1, $tester->getExitCode());
        self::assertSame([], $hookSpy->applied, 'apply hook must not run when refusal kicks in');
        self::assertStringContainsString(
            'Refusing to reset without --yes flag in non-interactive mode.',
            $tester->getStderr(),
        );
    }

    #[Test]
    public function interactive_yes_response_proceeds(): void
    {
        $this->seed(['role.admin']);
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        $stdin = $this->scriptedTty(['y']);
        $tester = $this->makeTester($hookSpy, $stdin);

        $tester->execute(['role.admin']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame(['role.admin'], $hookSpy->applied);
        self::assertStringContainsString('reset role.admin', $tester->getStdout());
    }

    #[Test]
    public function interactive_no_response_aborts_zero_exit_and_no_apply(): void
    {
        $this->seed(['role.admin']);
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        $stdin = $this->scriptedTty(['n']);
        $tester = $this->makeTester($hookSpy, $stdin);

        $tester->execute(['role.admin']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame([], $hookSpy->applied);
        self::assertStringContainsString('Aborted', $tester->getStdout());
    }

    #[Test]
    public function missing_sync_entity_exits_one_and_writes_stderr(): void
    {
        // No seed — sync store is empty.
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        $tester = $this->makeTester($hookSpy);

        $tester->execute(['role.ghost', '--yes']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('role.ghost', $tester->getStderr());
        self::assertSame([], $hookSpy->applied);
    }

    #[Test]
    public function reset_emits_op_reset_audit_event_with_skip_confirmation_true(): void
    {
        $this->seed(['role.admin']);
        $hookSpy = $this->spyHook(ConfigImportEntryResult::STATUS_UPDATED);
        /** @var list<ConfigAuditEvent> $events */
        $events = [];
        $logger = static function (string $_level, string $_message, ConfigAuditEvent $event) use (&$events): void {
            $events[] = $event;
        };
        $tester = $this->makeTester($hookSpy, auditLogger: $logger);

        $tester->execute(['role.admin', '--yes']);

        self::assertSame(0, $tester->getExitCode());
        self::assertCount(1, $events);
        self::assertSame(ConfigAuditEvent::OP_RESET, $events[0]->operation);
        self::assertSame('role.admin', $events[0]->entityRef);
        self::assertTrue($events[0]->context['skip_confirmation']);
    }

    /**
     * @param list<string> $refs
     */
    private function seed(array $refs): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($refs as $ref) {
            [$entityType, $entityId] = explode('.', $ref, 2);
            $repository->put(new ConfigSyncFile(
                entityType: $entityType,
                entityId: $entityId,
                uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
                dependencies: [],
                langcode: 'en',
                fields: [],
            ));
        }
    }

    /**
     * @return ConfigImportApplyHookInterface&object{applied: list<string>}
     */
    private function spyHook(string $returnStatus): ConfigImportApplyHookInterface
    {
        return new class($returnStatus) implements ConfigImportApplyHookInterface {
            /** @var list<string> */
            public array $applied = [];

            public function __construct(private readonly string $returnStatus) {}

            public function apply(ConfigSyncFile $file): string
            {
                $this->applied[] = $file->ref();

                return $this->returnStatus;
            }

            public function delete(string $ref): void {}
        };
    }

    /**
     * @param list<string> $lines
     */
    private function scriptedTty(array $lines): StdinSource
    {
        return new class($lines) implements StdinSource {
            /** @var list<string> */
            private array $lines;

            /** @param list<string> $lines */
            public function __construct(array $lines)
            {
                $this->lines = array_values($lines);
            }

            public function readLine(): ?string
            {
                if ($this->lines === []) {
                    return null;
                }

                return array_shift($this->lines);
            }

            public function isInteractive(): bool
            {
                return true;
            }
        };
    }

    private function makeTester(
        ConfigImportApplyHookInterface $hook,
        ?StdinSource $stdin = null,
        ?\Closure $auditLogger = null,
    ): CliTester {
        $repository = new ConfigSyncRepository($this->tempDir);
        $resetter = new ConfigResetter($repository, $hook, $auditLogger);
        $command = new ConfigResetCommand($resetter);

        return CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
            $stdin,
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'config:reset',
            description: 'Overwrite one active-store config entity with its sync-store value.',
            arguments: [
                new HandlerArgument(
                    name: 'ref',
                    mode: HandlerArgumentMode::Required,
                    description: 'Entity reference `<entity-type>.<id>`.',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'yes',
                    mode: HandlerOptionMode::None,
                    description: 'Skip the operator confirmation prompt.',
                ),
            ],
            handler: [ConfigResetCommand::class, 'execute'],
        );
    }

    private function makeContainer(ConfigResetCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ConfigResetCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === ConfigResetCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ConfigResetCommand::class;
            }
        };
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
