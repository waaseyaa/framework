<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\StorageInterface;

#[CoversClass(ConfigExportHandler::class)]
final class ConfigExportHandlerTest extends TestCase
{
    #[Test]
    public function exportsConfigurationAndShowsCount(): void
    {
        $mockStorage = $this->createMock(StorageInterface::class);
        $mockStorage->method('listAll')->willReturn(['system.site', 'system.performance', 'user.settings']);

        $mockManager = $this->createMock(ConfigManagerInterface::class);
        $mockManager->expects($this->once())->method('export');
        $mockManager->method('getActiveStorage')->willReturn($mockStorage);

        $tester = $this->createTester($mockManager);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Configuration exported. Active storage contains 3 items.', $tester->getStdout());
    }

    private function createTester(ConfigManagerInterface $manager): CliTester
    {
        $handler = new ConfigExportHandler($manager);
        $definition = new HandlerCommand(
            name: 'config:export',
            description: 'Export active configuration to the sync directory',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
