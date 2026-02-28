<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\EntityTypeListCommand;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EntityTypeListCommand::class)]
final class EntityTypeListCommandTest extends TestCase
{
    #[Test]
    public function it_lists_entity_types_in_a_table(): void
    {
        $nodeType = new EntityType(
            id: 'node',
            label: 'Content',
            class: 'Aurora\\Node\\Node',
            revisionable: true,
            translatable: true,
        );

        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: 'Aurora\\User\\User',
            revisionable: false,
            translatable: false,
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeType,
            'user' => $userType,
        ]);

        $tester = $this->createTester($manager);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('node', $output);
        $this->assertStringContainsString('Content', $output);
        $this->assertStringContainsString('user', $output);
        $this->assertStringContainsString('User', $output);
        $this->assertStringContainsString('Yes', $output);
        $this->assertStringContainsString('No', $output);
    }

    #[Test]
    public function it_shows_message_when_no_entity_types(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $tester = $this->createTester($manager);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No entity types registered.', $output);
    }

    private function createTester(EntityTypeManagerInterface $manager): CommandTester
    {
        $app = new Application();
        $app->add(new EntityTypeListCommand($manager));
        $command = $app->find('entity-type:list');

        return new CommandTester($command);
    }
}
