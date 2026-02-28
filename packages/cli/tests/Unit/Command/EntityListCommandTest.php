<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\EntityListCommand;
use Aurora\Entity\EntityInterface;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\Storage\EntityQueryInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EntityListCommand::class)]
class EntityListCommandTest extends TestCase
{
    #[Test]
    public function it_lists_entities_in_a_table(): void
    {
        $entity1 = $this->createMock(EntityInterface::class);
        $entity1->method('id')->willReturn(1);
        $entity1->method('label')->willReturn('First');

        $entity2 = $this->createMock(EntityInterface::class);
        $entity2->method('id')->willReturn(2);
        $entity2->method('label')->willReturn('Second');

        $mockQuery = $this->createMock(EntityQueryInterface::class);
        $mockQuery->method('accessCheck')->willReturnSelf();
        $mockQuery->method('range')->willReturnSelf();
        $mockQuery->method('execute')->willReturn([1, 2]);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->method('getQuery')->willReturn($mockQuery);
        $mockStorage->method('loadMultiple')
            ->with([1, 2])
            ->willReturn([1 => $entity1, 2 => $entity2]);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')
            ->with('node')
            ->willReturn($mockStorage);

        $app = new Application();
        $app->add(new EntityListCommand($mockManager));
        $command = $app->find('entity:list');
        $tester = new CommandTester($command);
        $tester->execute(['entity_type' => 'node']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('First', $display);
        $this->assertStringContainsString('Second', $display);
        $this->assertStringContainsString('ID', $display);
        $this->assertStringContainsString('Label', $display);
    }

    #[Test]
    public function it_shows_message_when_no_entities_found(): void
    {
        $mockQuery = $this->createMock(EntityQueryInterface::class);
        $mockQuery->method('accessCheck')->willReturnSelf();
        $mockQuery->method('range')->willReturnSelf();
        $mockQuery->method('execute')->willReturn([]);

        $mockStorage = $this->createMock(EntityStorageInterface::class);
        $mockStorage->method('getQuery')->willReturn($mockQuery);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($mockStorage);

        $app = new Application();
        $app->add(new EntityListCommand($mockManager));
        $command = $app->find('entity:list');
        $tester = new CommandTester($command);
        $tester->execute(['entity_type' => 'node']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No entities found.', $tester->getDisplay());
    }
}
