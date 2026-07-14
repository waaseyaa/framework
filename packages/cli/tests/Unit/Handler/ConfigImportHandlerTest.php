<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigImportResult;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Exception\ConfigImportFailedException;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator;

#[CoversClass(ConfigImportHandler::class)]
final class ConfigImportHandlerTest extends TestCase
{
    #[Test]
    public function importsConfigurationSuccessfully(): void
    {
        $result = new ConfigImportResult(
            created: ['system.site'],
            updated: ['user.settings', 'system.performance'],
            deleted: [],
        );

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->expects($this->once())->method('import')->willReturn($result);

        $tester = $this->createTester($mockManager);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        $this->assertStringContainsString('Created: 1', $stdout);
        $this->assertStringContainsString('Updated: 2', $stdout);
        $this->assertStringContainsString('Deleted: 0', $stdout);
        $this->assertStringContainsString('Configuration imported successfully.', $stdout);
    }

    #[Test]
    public function returnsFailureWhenErrorsOccur(): void
    {
        $result = new ConfigImportResult(
            created: [],
            updated: [],
            deleted: [],
            errors: ['Failed to import system.site'],
        );

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->method('import')->willReturn($result);

        $tester = $this->createTester($mockManager);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Failed to import system.site', $tester->getStderr());
    }

    #[Test]
    public function returnsFailureAndReportsMessageWhenImportThrows(): void
    {
        $exception = ConfigImportFailedException::applyFailed(
            'system.site',
            'write failed while creating this entry (entry 1 of 2 this pass; already applied — created: none; updated: none; deleted: none)',
        );

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->expects($this->once())->method('import')->willThrowException($exception);

        $tester = $this->createTester($mockManager);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('system.site', $tester->getStderr());
        $this->assertStringContainsString('write failed while creating this entry', $tester->getStderr());
    }

    #[Test]
    public function rejects_invalid_workflow_assignments_before_import_writes(): void
    {
        $sync = new MemoryStorage();
        $sync->write('workflows.assignments', ['node.article' => 'editorial']);
        $manager = $this->createMock(ConfigManagerInterface::class);
        $manager->method('getSyncStorage')->willReturn($sync);
        $manager->expects($this->never())->method('import');

        $definition = $this->createMock(EntityTypeInterface::class);
        $definition->method('isRevisionable')->willReturn(false);
        $entityTypes = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypes->method('hasDefinition')->with('node')->willReturn(true);
        $entityTypes->method('getDefinition')->with('node')->willReturn($definition);

        $provider = new class($manager, $entityTypes) extends ServiceProvider {
            public function __construct(
                private readonly ConfigManagerInterface $manager,
                private readonly EntityTypeManagerInterface $entityTypes,
            ) {}

            public function register(): void
            {
                $manager = $this->manager;
                $entityTypes = $this->entityTypes;
                $this->singleton(ConfigManagerInterface::class, static fn(): ConfigManagerInterface => $manager);
                $this->singleton(EntityTypeManager::class, static fn(): EntityTypeManagerInterface => $entityTypes);
            }
        };
        $provider->register();

        $kernel = new class(sys_get_temp_dir()) extends AbstractKernel {};
        (new \ReflectionProperty(AbstractKernel::class, 'providers'))->setValue($kernel, [$provider]);
        $handler = $kernel->buildHandlerContainer()->get(ConfigImportHandler::class);
        self::assertInstanceOf(ConfigImportHandler::class, $handler);
        self::assertInstanceOf(
            WorkflowAssignmentsValidator::class,
            (new \ReflectionProperty(ConfigImportHandler::class, 'workflowAssignmentsValidator'))->getValue($handler),
        );
        $command = new HandlerCommand(
            name: 'config:import',
            description: 'Import configuration from the sync directory',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $tester = CliTester::for($command, new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        });

        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('not revisionable', $tester->getStderr());
    }

    private function createTester(ConfigManagerInterface $manager): CliTester
    {
        $handler = new ConfigImportHandler($manager);
        $definition = new HandlerCommand(
            name: 'config:import',
            description: 'Import configuration from the sync directory',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
