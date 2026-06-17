<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\EntityTypeListHandler;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(EntityTypeListHandler::class)]
final class EntityTypeListHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new EntityTypeServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'entity-type:list') {
                return $cmd;
            }
        }

        throw new \RuntimeException('entity-type:list command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === EntityTypeListHandler::class) {
                    return new EntityTypeListHandler($this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === EntityTypeListHandler::class;
            }
        };
    }

    #[Test]
    public function listsEntityTypesInTable(): void
    {
        $nodeType = new EntityType(
            id: 'node',
            label: 'Content',
            class: 'Waaseyaa\\Node\\Node',
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'revision' => 'vid', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
            revisionable: true,
            translatable: true,
        );

        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: 'Waaseyaa\\User\\User',
            revisionable: false,
            translatable: false,
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeType,
            'user' => $userType,
        ]);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($manager));
        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('node', $output);
        self::assertStringContainsString('Content', $output);
        self::assertStringContainsString('user', $output);
        self::assertStringContainsString('User', $output);
        self::assertStringContainsString('Yes', $output);
        self::assertStringContainsString('No', $output);
    }

    #[Test]
    public function showsMessageWhenNoEntityTypes(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($manager));
        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No entity types registered.', $tester->getStdout());
    }
}
