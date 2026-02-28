<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\UserCreateCommand;
use Aurora\Entity\EntityInterface;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(UserCreateCommand::class)]
class UserCreateCommandTest extends TestCase
{
    #[Test]
    public function it_creates_a_user_with_username(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(1);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with(['name' => 'testuser'])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')
            ->with('user')
            ->willReturn($mockStorage);

        $app = new Application();
        $app->add(new UserCreateCommand($mockManager));
        $command = $app->find('user:create');
        $tester = new CommandTester($command);
        $tester->execute(['username' => 'testuser']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Created user "testuser" with ID: 1', $tester->getDisplay());
    }

    #[Test]
    public function it_creates_a_user_with_email_and_role(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(5);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with([
                'name' => 'admin',
                'email' => 'admin@example.com',
                'roles' => ['administrator'],
            ])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new UserCreateCommand($mockManager));
        $command = $app->find('user:create');
        $tester = new CommandTester($command);
        $tester->execute([
            'username' => 'admin',
            '--email' => 'admin@example.com',
            '--role' => 'administrator',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Created user "admin" with ID: 5', $tester->getDisplay());
    }
}
