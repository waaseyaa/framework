<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\ConfigImportCommand;
use Aurora\Config\ConfigImportResult;
use Aurora\Config\ConfigManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ConfigImportCommand::class)]
class ConfigImportCommandTest extends TestCase
{
    #[Test]
    public function it_imports_configuration_successfully(): void
    {
        $result = new ConfigImportResult(
            created: ['system.site'],
            updated: ['user.settings', 'system.performance'],
            deleted: [],
        );

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->expects($this->once())->method('import')->willReturn($result);

        $app = new Application();
        $app->add(new ConfigImportCommand($mockManager));
        $command = $app->find('config:import');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Created: 1', $display);
        $this->assertStringContainsString('Updated: 2', $display);
        $this->assertStringContainsString('Deleted: 0', $display);
        $this->assertStringContainsString('Configuration imported successfully.', $display);
    }

    #[Test]
    public function it_returns_failure_when_errors_occur(): void
    {
        $result = new ConfigImportResult(
            created: [],
            updated: [],
            deleted: [],
            errors: ['Failed to import system.site'],
        );

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->method('import')->willReturn($result);

        $app = new Application();
        $app->add(new ConfigImportCommand($mockManager));
        $command = $app->find('config:import');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Failed to import system.site', $tester->getDisplay());
    }
}
