<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
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
        $storage = $this->makeStorageThatReturnsIds([(string) $user->id()]);
        $controller = new AuthController();

        $found = $controller->findUserByName($storage, 'bob');

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame(3, $found->id());
    }

    #[Test]
    public function findUserByNameReturnsNullWhenNotFound(): void
    {
        $storage = $this->makeStorageThatReturnsIds([]);
        $controller = new AuthController();

        $found = $controller->findUserByName($storage, 'nobody');

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

            public function execute(): array
            {
                // Name lookup returns empty, mail lookup returns the user ID.
                return $this->callCount === 2 ? [(string) $this->user->id()] : [];
            }
        };

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('load')->with($user->id())->willReturn($user);

        $controller = new AuthController();
        $found = $controller->findUserByName($storage, 'eve@example.com');

        $this->assertInstanceOf(User::class, $found);
        $this->assertSame(8, $found->id());
    }

    #[Test]
    public function findUserByNameQueryIncludesStatusOneCondition(): void
    {
        /** @var list<array{field: string, value: mixed, operator: string}> $capturedConditions */
        $capturedConditions = [];
        $storage = $this->makeCapturingStorage($capturedConditions, []);
        $controller = new AuthController();

        $controller->findUserByName($storage, 'dave');

        $this->assertContains(
            ['field' => 'status', 'value' => 1, 'operator' => '='],
            $capturedConditions,
            'findUserByName must filter by status=1 to exclude blocked users.',
        );
    }

    /**
     * @param array<int|string> $ids
     */
    private function makeStorageThatReturnsIds(array $ids): EntityStorageInterface
    {
        $user = $ids !== [] ? new User(['uid' => (int) reset($ids), 'name' => 'bob']) : null;
        $ignored = [];
        $storage = $this->makeCapturingStorage($ignored, $ids);

        if ($user !== null) {
            $storage->method('load')->with((int) reset($ids))->willReturn($user);
        } else {
            $storage->method('load')->willReturn(null);
        }

        return $storage;
    }

    /**
     * @param list<array{field: string, value: mixed, operator: string}> $capturedConditions
     * @param array<int|string> $idsToReturn
     */
    private function makeCapturingStorage(array &$capturedConditions, array $idsToReturn): EntityStorageInterface
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
            public function execute(): array { return $this->ids; }
        };

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        return $storage;
    }
}
