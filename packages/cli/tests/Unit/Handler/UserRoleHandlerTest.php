<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\UserRoleHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

#[CoversClass(UserRoleHandler::class)]
final class UserRoleHandlerTest extends TestCase
{
    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new UserPermissionServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'user:role') {
                return $cmd;
            }
        }

        throw new \RuntimeException('user:role command definition not found');
    }

    private function makeContainer(EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($manager) implements ContainerInterface {
            public function __construct(private readonly EntityTypeManagerInterface $manager) {}

            public function get(string $id): mixed
            {
                if ($id === UserRoleHandler::class) {
                    return new UserRoleHandler($this->manager, new UserInternalFieldReaderFixture());
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === UserRoleHandler::class;
            }
        };
    }

    private function makeMockUser(string $userId, array $roles): EntityInterface
    {
        return new User([
            'uid' => (int) $userId,
            'name' => 'fixture-user-' . $userId,
            'roles' => $roles,
            'permissions' => [],
        ]);
    }

    private function makeStorage(?EntityInterface $user): EntityStorageInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);
        if ($user !== null) {
            $storage->method('save');
        }

        return $storage;
    }

    // C-22 WP3: read/write path now goes through the canonical repository.
    private function makeRepository(?EntityInterface $user): EntityRepositoryInterface
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($user);
        if ($user !== null) {
            $repository->method('save');
        }

        return $repository;
    }

    #[Test]
    public function addsRoleToUser(): void
    {
        $user = $this->makeMockUser('1', []);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($this->makeStorage($user));
        $mockManager->method('getRepository')->willReturn($this->makeRepository($user));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['user_id' => '1', 'role' => 'editor']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Added role "editor" to user 1.', $tester->getStdout());
    }

    #[Test]
    public function removesRoleFromUser(): void
    {
        $user = $this->makeMockUser('1', ['editor']);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($this->makeStorage($user));
        $mockManager->method('getRepository')->willReturn($this->makeRepository($user));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['user_id' => '1', 'role' => 'editor', '--remove' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Removed role "editor" from user 1.', $tester->getStdout());
    }

    #[Test]
    public function reportsWhenUserAlreadyHasRole(): void
    {
        $user = $this->makeMockUser('1', ['editor']);

        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($this->makeStorage($user));
        $mockManager->method('getRepository')->willReturn($this->makeRepository($user));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['user_id' => '1', 'role' => 'editor']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('already has role "editor"', $tester->getStdout());
    }

    #[Test]
    public function returnsFailureWhenUserNotFound(): void
    {
        $mockManager = $this->createMock(EntityTypeManagerInterface::class);
        $mockManager->method('getStorage')->willReturn($this->makeStorage(null));
        $mockManager->method('getRepository')->willReturn($this->makeRepository(null));

        $definition = $this->makeDefinition();
        $tester = CliTester::for($definition, $this->makeContainer($mockManager));
        $tester->executeMap(['user_id' => '999', 'role' => 'editor']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('User with ID "999" not found.', $tester->getStderr());
    }
}
