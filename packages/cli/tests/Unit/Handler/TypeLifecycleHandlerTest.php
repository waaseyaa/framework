<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\TypeDisableHandler;
use Waaseyaa\CLI\Handler\TypeEnableHandler;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(TypeEnableHandler::class)]
#[CoversClass(TypeDisableHandler::class)]
final class TypeLifecycleHandlerTest extends TestCase
{
    private string $tempDir;
    private EntityTypeLifecycleManager $lifecycle;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_lifecycle_handler_test_' . uniqid();
        mkdir($this->tempDir . '/storage/framework', 0755, true);

        $this->lifecycle = new EntityTypeLifecycleManager($this->tempDir);

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
        ));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/storage/framework/*') ?: []);
        @rmdir($this->tempDir . '/storage/framework');
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir);
    }

    // -----------------------------------------------------------------------
    // type:enable
    // -----------------------------------------------------------------------

    #[Test]
    public function enablesDisabledTypeAndRecordsAuditEntry(): void
    {
        $this->lifecycle->disable('note', 'setup', null);

        $tester = $this->runEnable(['type' => 'note']);

        self::assertSame(0, $tester->getExitCode());
        self::assertFalse($this->lifecycle->isDisabled('note', null));

        $log = $this->lifecycle->readAuditLog('note');
        self::assertCount(2, $log);
        self::assertSame('enabled', $log[1]['action']);
    }

    #[Test]
    public function enableIsIdempotentForAlreadyEnabledType(): void
    {
        $tester = $this->runEnable(['type' => 'note']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('already enabled', $tester->getStdout());
    }

    #[Test]
    public function enableFailsForUnknownType(): void
    {
        $tester = $this->runEnable(['type' => 'nonexistent']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown entity type', $tester->getStderr());
    }

    #[Test]
    public function enablesTypeForSpecificTenant(): void
    {
        $this->lifecycle->disable('note', 'setup', 'acme');

        $tester = $this->runEnable(['type' => 'note', '--tenant' => 'acme']);

        self::assertSame(0, $tester->getExitCode());
        self::assertFalse($this->lifecycle->isDisabled('note', 'acme'));
    }

    // -----------------------------------------------------------------------
    // type:disable
    // -----------------------------------------------------------------------

    #[Test]
    public function disablesTypeAndRecordsAuditEntry(): void
    {
        $tester = $this->runDisable(['type' => 'note', '--yes' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertTrue($this->lifecycle->isDisabled('note'));
        self::assertStringContainsString('Disabled', $tester->getStdout());
    }

    #[Test]
    public function disableFailsForUnknownType(): void
    {
        $tester = $this->runDisable(['type' => 'nonexistent', '--yes' => true]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown entity type', $tester->getStderr());
    }

    #[Test]
    public function disableIsIdempotentForAlreadyDisabledType(): void
    {
        $this->lifecycle->disable('note', 'test');

        $tester = $this->runDisable(['type' => 'note', '--yes' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('already disabled', $tester->getStdout());
    }

    #[Test]
    public function disableFailsWhenItWouldLeaveNoEnabledTypes(): void
    {
        $this->lifecycle->disable('article', 'test');

        $tester = $this->runDisable(['type' => 'note', '--yes' => true]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('DEFAULT_TYPE_DISABLED', $tester->getStderr());
        self::assertFalse($this->lifecycle->isDisabled('note'));
    }

    #[Test]
    public function disableWithForceAllowsDisablingLastType(): void
    {
        $this->lifecycle->disable('article', 'test');

        $tester = $this->runDisable(['type' => 'note', '--yes' => true, '--force' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertTrue($this->lifecycle->isDisabled('note'));
        self::assertStringContainsString('DEFAULT_TYPE_DISABLED', $tester->getStdout());
    }

    #[Test]
    public function disableWritesAuditEntry(): void
    {
        $this->runDisable(['type' => 'note', '--actor' => 'admin-42', '--yes' => true]);

        $log = $this->lifecycle->readAuditLog('note');
        self::assertCount(1, $log);
        self::assertSame('admin-42', $log[0]['actor_id']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $inputs
     */
    private function runEnable(array $inputs): CliTester
    {
        $definition = $this->findDefinition('type:enable');
        $tester = CliTester::for($definition, $this->makeEnableContainer());

        return $tester->executeMap($inputs);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function runDisable(array $inputs): CliTester
    {
        $definition = $this->findDefinition('type:disable');
        $tester = CliTester::for($definition, $this->makeDisableContainer());

        return $tester->executeMap($inputs);
    }

    private function makeEnableContainer(): ContainerInterface
    {
        $manager = $this->entityTypeManager;
        $lifecycle = $this->lifecycle;

        return new class ($manager, $lifecycle) implements ContainerInterface {
            public function __construct(
                private readonly EntityTypeManager $manager,
                private readonly EntityTypeLifecycleManager $lifecycle,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === TypeEnableHandler::class) {
                    return new TypeEnableHandler($this->manager, $this->lifecycle);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === TypeEnableHandler::class;
            }
        };
    }

    private function makeDisableContainer(): ContainerInterface
    {
        $manager = $this->entityTypeManager;
        $lifecycle = $this->lifecycle;

        return new class ($manager, $lifecycle) implements ContainerInterface {
            public function __construct(
                private readonly EntityTypeManager $manager,
                private readonly EntityTypeLifecycleManager $lifecycle,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === TypeDisableHandler::class) {
                    return new TypeDisableHandler($this->manager, $this->lifecycle);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === TypeDisableHandler::class;
            }
        };
    }

    private function findDefinition(string $name): HandlerCommand
    {
        $provider = new EntityTypeServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === $name) {
                return $cmd;
            }
        }

        throw new \RuntimeException(sprintf('%s command definition not found', $name));
    }
}
