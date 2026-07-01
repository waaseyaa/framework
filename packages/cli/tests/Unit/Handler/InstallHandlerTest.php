<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\InstallHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[CoversClass(InstallHandler::class)]
final class InstallHandlerTest extends TestCase
{
    private function makeDefinition(InstallHandler $handler): HandlerCommand
    {
        return new HandlerCommand(
            name: 'install',
            description: 'Install Waaseyaa with initial configuration',
            options: [
                new HandlerOption(name: 'site-name', mode: HandlerOptionMode::Required, description: 'The name of the site', default: 'Waaseyaa'),
                new HandlerOption(name: 'site-mail', mode: HandlerOptionMode::Required, description: 'Site email address', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-email', mode: HandlerOptionMode::Required, description: 'Admin user email', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-password', mode: HandlerOptionMode::Required, description: 'Admin user password'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in unit tests');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function it_installs_with_default_site_name(): void
    {
        $mockStorage = $this->createMock(StorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('write')
            ->with('system.site', $this->callback(static function (array $data): bool {
                return $data['name'] === 'Waaseyaa' && $data['mail'] === 'admin@example.com';
            }));

        $mockConfigManager = $this->createMock(ConfigManagerInterface::class);
        $mockConfigManager->method('getActiveStorage')->willReturn($mockStorage);

        $mockEntity = $this->createMock(EntityInterface::class);

        $mockEntityRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockEntityRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (array $values): bool {
                return $values['name'] === 'admin' && $values['roles'] === ['administrator'];
            }))
            ->willReturn($mockEntity);
        $mockEntityRepository->expects($this->once())->method('save');

        $mockEntityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockEntityTypeManager->method('getRepository')->with('user')->willReturn($mockEntityRepository);

        $handler = new InstallHandler(
            entityTypeManager: $mockEntityTypeManager,
            configManager: $mockConfigManager,
        );

        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Creating admin user...', $tester->getStdout());
        self::assertStringContainsString('Waaseyaa "Waaseyaa" installed successfully.', $tester->getStdout());
    }

    #[Test]
    public function it_installs_with_custom_site_name(): void
    {
        $mockStorage = $this->createMock(StorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('write')
            ->with('system.site', $this->callback(static function (array $data): bool {
                return $data['name'] === 'My Site';
            }));

        $mockConfigManager = $this->createMock(ConfigManagerInterface::class);
        $mockConfigManager->method('getActiveStorage')->willReturn($mockStorage);

        $mockEntity = $this->createMock(EntityInterface::class);

        $mockEntityRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockEntityRepository->method('create')->willReturn($mockEntity);
        $mockEntityRepository->method('save');

        $mockEntityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockEntityTypeManager->method('getRepository')->willReturn($mockEntityRepository);

        $handler = new InstallHandler(
            entityTypeManager: $mockEntityTypeManager,
            configManager: $mockConfigManager,
        );

        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--site-name' => 'My Site']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Waaseyaa "My Site" installed successfully.', $tester->getStdout());
    }
}
