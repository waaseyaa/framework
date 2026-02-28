<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\ConfigExportCommand;
use Aurora\Config\ConfigManagerInterface;
use Aurora\Config\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ConfigExportCommand::class)]
class ConfigExportCommandTest extends TestCase
{
    #[Test]
    public function it_exports_configuration_and_shows_count(): void
    {
        $mockStorage = $this->createMock(StorageInterface::class);
        $mockStorage->method('listAll')->willReturn(['system.site', 'system.performance', 'user.settings']);

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->expects($this->once())->method('export');
        $mockManager->method('getActiveStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new ConfigExportCommand($mockManager));
        $command = $app->find('config:export');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Exported 3 configuration items.', $tester->getDisplay());
    }
}
