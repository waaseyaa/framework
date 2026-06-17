<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\PermissionHandlerInterface;
use Waaseyaa\CLI\Handler\PermissionListHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(PermissionListHandler::class)]
final class PermissionListHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new UserPermissionServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'permission:list') {
                return $cmd;
            }
        }

        throw new \RuntimeException('permission:list command definition not found');
    }

    private function makeContainer(PermissionHandlerInterface $permissionHandler): ContainerInterface
    {
        return new class ($permissionHandler) implements ContainerInterface {
            public function __construct(private readonly PermissionHandlerInterface $permissionHandler) {}

            public function get(string $id): mixed
            {
                if ($id === PermissionListHandler::class) {
                    return new PermissionListHandler($this->permissionHandler);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === PermissionListHandler::class;
            }
        };
    }

    #[Test]
    public function listsPermissions(): void
    {
        $handler = $this->createMock(PermissionHandlerInterface::class);
        $handler->method('getPermissions')->willReturn([
            'access content' => [
                'title' => 'Access content',
                'description' => 'View published content',
            ],
            'administer nodes' => [
                'title' => 'Administer nodes',
                'description' => 'Manage all content',
            ],
        ]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($handler));
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('access content', $tester->getStdout());
        self::assertStringContainsString('Access content', $tester->getStdout());
        self::assertStringContainsString('View published content', $tester->getStdout());
        self::assertStringContainsString('administer nodes', $tester->getStdout());
    }

    #[Test]
    public function showsMessageWhenNoPermissions(): void
    {
        $handler = $this->createMock(PermissionHandlerInterface::class);
        $handler->method('getPermissions')->willReturn([]);

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($handler));
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No permissions registered.', $tester->getStdout());
    }
}
