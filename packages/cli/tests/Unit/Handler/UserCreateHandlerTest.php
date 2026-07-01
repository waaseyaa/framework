<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\UserCreateHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[CoversClass(UserCreateHandler::class)]
final class UserCreateHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new UserPermissionServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'user:create') {
                return $cmd;
            }
        }

        throw new \RuntimeException('user:create command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === UserCreateHandler::class) {
                    return new UserCreateHandler($this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === UserCreateHandler::class;
            }
        };
    }

    #[Test]
    public function createsUserWithUsername(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(1);

        $mockRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockRepository->expects($this->once())
            ->method('create')
            ->with(['name' => 'alice'])
            ->willReturn($mockEntity);
        $mockRepository->expects($this->once())
            ->method('save')
            ->with($mockEntity);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getRepository')
            ->with('user')
            ->willReturn($mockRepository);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['username' => 'alice']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created user "alice" with ID: 1', $tester->getStdout());
    }

    #[Test]
    public function createsUserWithEmailPasswordAndRole(): void
    {
        $mockEntity = $this->createMock(EntityInterface::class);
        $mockEntity->method('id')->willReturn(42);

        $mockRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockRepository->method('create')->willReturn($mockEntity);
        $mockRepository->method('save');

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getRepository')->willReturn($mockRepository);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap([
            'username' => 'bob',
            '--email' => 'bob@example.com',
            '--password' => 'secret',
            '--role' => 'editor',
        ]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Created user "bob" with ID: 42', $tester->getStdout());
    }

    #[Test]
    public function returnsFailureOnStorageException(): void
    {
        $mockRepository = $this->createMock(EntityRepositoryInterface::class);
        $mockRepository->method('create')->willThrowException(new \RuntimeException('DB error'));

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getRepository')->willReturn($mockRepository);

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['username' => 'fail']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Failed to create user "fail"', $tester->getStderr());
    }
}
