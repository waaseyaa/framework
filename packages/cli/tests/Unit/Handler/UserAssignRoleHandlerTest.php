<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\UserAssignRoleHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;

#[CoversClass(UserAssignRoleHandler::class)]
#[CoversClass(RoleRepository::class)]
final class UserAssignRoleHandlerTest extends TestCase
{
    private function makeDefinition(): HandlerCommand
    {
        $provider = new UserPermissionServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'user:assign-role') {
                return $cmd;
            }
        }

        throw new \RuntimeException('user:assign-role command definition not found');
    }

    private function makeRegistry(): RoleRepository
    {
        return new RoleRepository([
            new Role(id: 'administrator', label: 'Admin', permissions: ['administer site']),
            new Role(id: 'editor', label: 'Editor', permissions: ['edit content', 'publish content']),
            new Role(id: 'reviewer', label: 'Reviewer', permissions: ['review content']),
        ]);
    }

    /**
     * In-memory entity double that tracks set() mutations so the handler's
     * roles + permissions recomputation can be asserted after save.
     *
     * @param array<int, string> $roles
     */
    private function makeUser(string $id, array $roles): EntityInterface
    {
        return new class ($id, $roles) implements EntityInterface {
            /** @var array<string, mixed> */
            public array $values;

            /** @param array<int, string> $roles */
            public function __construct(private readonly string $id, array $roles)
            {
                $this->values = ['roles' => $roles, 'permissions' => []];
            }

            public function id(): int|string|null
            {
                return $this->id;
            }

            public function uuid(): string
            {
                return 'uuid-' . $this->id;
            }

            public function label(): string
            {
                return 'user-' . $this->id;
            }

            public function getEntityTypeId(): string
            {
                return 'user';
            }

            public function bundle(): string
            {
                return 'user';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, mixed $value): static
            {
                $this->values[$name] = $value;

                return $this;
            }

            public function toArray(): array
            {
                return $this->values;
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }

    private function makeStorage(?EntityInterface $user): EntityStorageInterface
    {
        return new class ($user) implements EntityStorageInterface {
            public function __construct(private readonly ?EntityInterface $user) {}

            public function create(array $values = []): EntityInterface
            {
                throw new \RuntimeException('not used');
            }

            public function load(int|string $id): ?EntityInterface
            {
                return $this->user;
            }

            public function loadByKey(string $key, mixed $value): ?EntityInterface
            {
                return null;
            }

            public function loadMultiple(array $ids = []): array
            {
                return [];
            }

            public function save(EntityInterface $entity): int
            {
                return 2;
            }

            public function delete(array $entities): void {}

            public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
            {
                throw new \RuntimeException('not used');
            }

            public function getEntityTypeId(): string
            {
                return 'user';
            }
        };
    }

    private function makeContainer(RoleRepository $registry, EntityTypeManagerInterface $manager): ContainerInterface
    {
        return new class ($registry, $manager) implements ContainerInterface {
            public function __construct(
                private readonly RoleRepository $registry,
                private readonly EntityTypeManagerInterface $manager,
            ) {}

            public function get(string $id): mixed
            {
                if ($id === UserAssignRoleHandler::class) {
                    return new UserAssignRoleHandler($this->registry, $this->manager);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === UserAssignRoleHandler::class;
            }
        };
    }

    private function makeManager(EntityStorageInterface $storage): EntityTypeManagerInterface
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getStorage')->willReturn($storage);

        return $manager;
    }

    #[Test]
    public function assigningEditorStampsRoleAndPermissions(): void
    {
        $user = $this->makeUser('1', []);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '1', 'role' => 'editor']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame(['editor'], $user->get('roles'));
        self::assertEqualsCanonicalizing(['edit content', 'publish content'], $user->get('permissions'));
        self::assertStringContainsString('Assigned role "editor" to user 1.', $tester->getStdout());
    }

    #[Test]
    public function assigningAdministratorStampsAdministratorRole(): void
    {
        $user = $this->makeUser('2', []);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '2', 'role' => 'administrator']);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame(['administrator'], $user->get('roles'));
        self::assertSame(['administer site'], $user->get('permissions'));
    }

    #[Test]
    public function assigningReplacesSiblingRegistryRoleButKeepsNonRegistryRoles(): void
    {
        // Holds an existing registry role (editor) plus a non-registry role (vip).
        $user = $this->makeUser('3', ['editor', 'vip']);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '3', 'role' => 'reviewer']);

        self::assertSame(0, $tester->getExitCode());
        // editor (registry sibling) replaced; vip (non-registry) preserved.
        self::assertEqualsCanonicalizing(['vip', 'reviewer'], $user->get('roles'));
        self::assertEqualsCanonicalizing(['review content'], $user->get('permissions'));
    }

    #[Test]
    public function unionSemanticsComposePermissionsAcrossHeldRoles(): void
    {
        // User already holds two registry roles (editor + reviewer). Removing
        // reviewer recomputes permissions as the union over the remaining held
        // registry roles, which here is editor alone. This exercises the
        // multi-role union code path on the recompute side.
        $user = $this->makeUser('4', ['editor', 'reviewer']);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '4', 'role' => 'reviewer', '--remove' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame(['editor'], $user->get('roles'));
        self::assertEqualsCanonicalizing(['edit content', 'publish content'], $user->get('permissions'));
        self::assertStringContainsString('Removed role "reviewer" from user 4.', $tester->getStdout());
    }

    #[Test]
    public function removeStripsRoleAndRecomputesPermissions(): void
    {
        $user = $this->makeUser('5', ['editor']);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '5', 'role' => 'editor', '--remove' => true]);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame([], $user->get('roles'));
        self::assertSame([], $user->get('permissions'));
    }

    #[Test]
    public function unknownRoleErrorsAndListsAvailable(): void
    {
        $user = $this->makeUser('6', []);
        $manager = $this->makeManager($this->makeStorage($user));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '6', 'role' => 'ghost']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown role "ghost".', $tester->getStderr());
        self::assertStringContainsString('editor', $tester->getStderr());
    }

    #[Test]
    public function returnsFailureWhenUserNotFound(): void
    {
        $manager = $this->makeManager($this->makeStorage(null));
        $registry = $this->makeRegistry();

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($registry, $manager));
        $tester->executeMap(['user_id' => '999', 'role' => 'editor']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('User with ID "999" not found.', $tester->getStderr());
    }
}
