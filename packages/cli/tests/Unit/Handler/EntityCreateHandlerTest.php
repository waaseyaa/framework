<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(EntityCreateHandler::class)]
final class EntityCreateHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new EntityTypeServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'entity:create') {
                return $cmd;
            }
        }

        throw new \RuntimeException('entity:create command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === EntityCreateHandler::class) {
                    return new EntityCreateHandler($this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === EntityCreateHandler::class;
            }
        };
    }

    #[Test]
    public function createsEntityWithGivenValues(): void
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

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node', '--values' => '{"title":"Test"}']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created node entity with ID: 42', $tester->getStdout());
    }

    #[Test]
    public function createsEntityWithDefaultEmptyValues(): void
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

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created node entity with ID: 1', $tester->getStdout());
    }

    #[Test]
    public function failsOnInvalidJson(): void
    {
        $mockManager = $this->createMock(EntityTypeManagerInterface::class);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node', '--values' => 'not-json']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Invalid JSON', $tester->getStderr());
    }
}
