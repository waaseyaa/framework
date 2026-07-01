<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\EntityListHandler;
use Waaseyaa\CLI\Provider\EntityTypeServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;

#[CoversClass(EntityListHandler::class)]
final class EntityListHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new EntityTypeServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'entity:list') {
                return $cmd;
            }
        }

        throw new \RuntimeException('entity:list command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === EntityListHandler::class) {
                    return new EntityListHandler($this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === EntityListHandler::class;
            }
        };
    }

    #[Test]
    public function listsEntitiesInTable(): void
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

        // C-22 WP3: read path now goes through the canonical repository.
        $mockRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockRepository->method('getQuery')->willReturn($mockQuery);
        $mockRepository->method('findMany')->willReturn([$entity1, $entity2]);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getRepository')->willReturn($mockRepository);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node']);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('First', $output);
        self::assertStringContainsString('Second', $output);
    }

    #[Test]
    public function showsMessageWhenNoEntitiesFound(): void
    {
        $mockQuery = $this->createMock(EntityQueryInterface::class);
        $mockQuery->method('accessCheck')->willReturnSelf();
        $mockQuery->method('range')->willReturnSelf();
        $mockQuery->method('execute')->willReturn([]);

        // C-22: the query builder now lives on the repository.
        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getRepository')->willReturn(new QueryOnlyStubRepository($mockQuery));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['entity_type' => 'node']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No entities found.', $tester->getStdout());
    }
}
