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
