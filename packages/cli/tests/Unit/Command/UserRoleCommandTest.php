<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\UserRoleCommand;
use Aurora\Entity\ContentEntityInterface;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(UserRoleCommand::class)]
class UserRoleCommandTest extends TestCase
{
    #[Test]
    public function it_adds_a_role_to_a_user(): void
    {
        $mockUser = $this->createMock(ContentEntityInterface::class);
        $mockUser->method('get')
            ->with('roles')
            ->willReturn(['authenticated']);
        $mockUser->expects($this->once())
            ->method('set')
            ->with('roles', ['authenticated', 'editor'])
            ->willReturnSelf();

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->method('load')
            ->with('1')
            ->willReturn($mockUser);
        $mockStorage->expects($this->once())->method('save')->with($mockUser);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')
            ->with('user')
            ->willReturn($mockStorage);

        $app = new Application();
        $app->add(new UserRoleCommand($mockManager));
        $command = $app->find('user:role');
        $tester = new CommandTester($command);
        $tester->execute([
            'user_id' => '1',
            'role' => 'editor',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Added role "editor" to user 1.', $tester->getDisplay());
    }

    #[Test]
    public function it_removes_a_role_from_a_user(): void
    {
        $mockUser = $this->createMock(ContentEntityInterface::class);
        $mockUser->method('get')
            ->with('roles')
            ->willReturn(['authenticated', 'editor']);
        $mockUser->expects($this->once())
            ->method('set')
            ->with('roles', ['authenticated'])
            ->willReturnSelf();

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->method('load')->willReturn($mockUser);
        $mockStorage->expects($this->once())->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new UserRoleCommand($mockManager));
        $command = $app->find('user:role');
        $tester = new CommandTester($command);
        $tester->execute([
            'user_id' => '1',
            'role' => 'editor',
            '--remove' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Removed role "editor" from user 1.', $tester->getDisplay());
    }

    #[Test]
    public function it_returns_failure_when_user_not_found(): void
    {
        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->method('load')->willReturn(null);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new UserRoleCommand($mockManager));
        $command = $app->find('user:role');
        $tester = new CommandTester($command);
        $tester->execute([
            'user_id' => '999',
            'role' => 'editor',
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('User with ID "999" not found.', $tester->getDisplay());
    }
}
