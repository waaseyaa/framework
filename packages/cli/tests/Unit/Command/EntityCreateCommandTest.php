<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\EntityCreateCommand;
use Aurora\Entity\EntityInterface;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EntityCreateCommand::class)]
class EntityCreateCommandTest extends TestCase
{
    #[Test]
    public function it_creates_an_entity_with_given_values(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(42);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with(['title' => 'Test'])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())
            ->method('save')
            ->with($mockEntity);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')
            ->with('node')
            ->willReturn($mockStorage);

        $app = new Application();
        $app->add(new EntityCreateCommand($mockManager));
        $command = $app->find('entity:create');
        $tester = new CommandTester($command);
        $tester->execute([
            'entity_type' => 'node',
            '--values' => '{"title":"Test"}',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Created node entity with ID: 42', $tester->getDisplay());
    }

    #[Test]
    public function it_creates_an_entity_with_default_empty_values(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(1);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('create')
            ->with([])
            ->willReturn($mockEntity);
        $mockStorage->expects($this->once())->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new EntityCreateCommand($mockManager));
        $command = $app->find('entity:create');
        $tester = new CommandTester($command);
        $tester->execute(['entity_type' => 'node']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Created node entity with ID: 1', $tester->getDisplay());
    }
}
