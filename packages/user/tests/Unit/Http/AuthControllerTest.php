<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Http\AuthController;
use Waaseyaa\User\User;

#[CoversClass(AuthController::class)]
final class AuthControllerTest extends TestCase
{
    #[Test]
    public function meReturnsUserDataForAuthenticatedAccount(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'mail' => 'alice@example.com', 'roles' => ['editor']]);
        $controller = new AuthController();

        $result = $controller->me($user);

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(5, $result['data']['id']);
        $this->assertSame('alice', $result['data']['name']);
        $this->assertSame('alice@example.com', $result['data']['email']);
        $this->assertSame(['editor'], $result['data']['roles']);
    }

    #[Test]
    public function meReturns401ForAnonymousAccount(): void
    {
        $controller = new AuthController();

        $result = $controller->me(new AnonymousUser());

        $this->assertSame(401, $result['statusCode']);
    }

    #[Test]
    public function meRolesUsesAccountInterface(): void
    {
        // AccountInterface::getRoles() must be used directly — no conditional branch for User vs AccountInterface.
        $user = new User(['uid' => 7, 'name' => 'carol', 'roles' => ['admin', 'editor']]);
        $controller = new AuthController();

        $result = $controller->me($user);

        $this->assertSame(['admin', 'editor'], $result['data']['roles']);
    }

    #[Test]
    public function findUserByNameReturnsUserWhenFound(): void
    {
        $user = new User(['uid' => 3, 'name' => 'bob']);
        [$storage, $repository] = $this->makeStorageAndRepositoryThatReturnIds([(string) $user->id()]);
        $controller = new AuthController();

        $found = $controller->findUserByName($storage, $repository, 'bob');

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame(3, $found->id());
    }

    #[Test]
    public function findUserByNameReturnsNullWhenNotFound(): void
    {
        [$storage, $repository] = $this->makeStorageAndRepositoryThatReturnIds([]);
        $controller = new AuthController();

        $found = $controller->findUserByName($storage, $repository, 'nobody');

        $this->assertNull($found);
    }

    #[Test]
    public function findUserByNameFindsUserByEmail(): void
    {
        $user = new User(['uid' => 8, 'name' => 'eve', 'mail' => 'eve@example.com']);

        // Build a query that returns [] for name lookup but the user for mail lookup.
        $callCount = 0;
        $query = new class($user, $callCount) implements EntityQueryInterface {
            public function __construct(
                private readonly User $user,
                private int &$callCount,
            ) {}

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                if ($field === 'name') {
                    $this->callCount = 1; // first query: by name
                } elseif ($field === 'mail') {
                    $this->callCount = 2; // second query: by mail
                }
                return $this;
            }

            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function setAccount(?AccountInterface $account): static { return $this; }

            public function execute(): array
            {
                // Name lookup returns empty, mail lookup returns the user ID.
                return $this->callCount === 2 ? [(string) $this->user->id()] : [];
            }
        };

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->with($user->id())->willReturn($user);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $controller = new AuthController();
        $found = $controller->findUserByName($storage, $repository, 'eve@example.com');

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame(8, $found->id());
    }

    #[Test]
    public function findUserByNameQueryIncludesStatusOneCondition(): void
    {
        /** @var list<array{field: string, value: mixed, operator: string}> $capturedConditions */
        $capturedConditions = [];
        [$storage, $repository] = $this->makeCapturingStorageAndRepository($capturedConditions, []);
        $controller = new AuthController();

        $controller->findUserByName($storage, $repository, 'dave');

        $this->assertContains(
            ['field' => 'status', 'value' => 1, 'operator' => '='],
            $capturedConditions,
            'findUserByName must filter by status=1 to exclude blocked users.',
        );
    }

    #[Test]
    public function findUserByNameDisablesAccessCheckForPreAuthLookup(): void
    {
        // Pre-authentication identity lookup is a system context: there is no
        // bound account yet, so the SqlEntityQuery fail-closed guard
        // (MissingQueryAccountException) surfaces as a 500 otherwise. Mirrors
        // SqlEntityStorage::loadByKey (mission sql-entity-query-access-
        // checking-01KRYP15, C-004) for the dual name/mail lookup.

        $query = new RecordingEntityQuery();

        $storage = $this->createMock(EntityStorageInterface::class);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $controller = new AuthController();
        $controller->findUserByName($storage, $repository, 'dave');

        $this->assertSame(
            [false, false],
            $query->accessChecks,
            'findUserByName must call accessCheck(false) on both the name lookup and the mail fallback (pre-auth system context).',
        );
    }

    /**
     * @param array<int|string> $ids
     * @return array{0: EntityStorageInterface, 1: EntityRepositoryInterface}
     */
    private function makeStorageAndRepositoryThatReturnIds(array $ids): array
    {
        $user = $ids !== [] ? new User(['uid' => (int) reset($ids), 'name' => 'bob']) : null;
        $ignored = [];
        [$storage, $repository] = $this->makeCapturingStorageAndRepository($ignored, $ids);

        if ($user !== null) {
            $storage->method('load')->with((int) reset($ids))->willReturn($user);
        } else {
            $storage->method('load')->willReturn(null);
        }

        return [$storage, $repository];
    }

    /**
     * @param list<array{field: string, value: mixed, operator: string}> $capturedConditions
     * @param array<int|string> $idsToReturn
     * @return array{0: EntityStorageInterface, 1: EntityRepositoryInterface}
     */
    private function makeCapturingStorageAndRepository(array &$capturedConditions, array $idsToReturn): array
    {
        $query = new class($idsToReturn, $capturedConditions) implements EntityQueryInterface {
            /** @param list<array{field: string, value: mixed, operator: string}> $conditions */
            public function __construct(
                private readonly array $ids,
                private array &$conditions,
            ) {}

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                $this->conditions[] = ['field' => $field, 'value' => $value, 'operator' => $operator];
                return $this;
            }

            public function exists(string $field): static { return $this; }
            public function notExists(string $field): static { return $this; }
            public function sort(string $field, string $direction = 'ASC'): static { return $this; }
            public function range(int $offset, int $limit): static { return $this; }
            public function count(): static { return $this; }
            public function accessCheck(bool $check = true): static { return $this; }
            public function setAccount(?AccountInterface $account): static { return $this; }
            public function execute(): array { return $this->ids; }
        };

        $storage = $this->createMock(EntityStorageInterface::class);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        return [$storage, $repository];
    }
}
