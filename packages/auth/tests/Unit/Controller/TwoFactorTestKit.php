<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\User;

/**
 * Helpers shared by the four two-factor controller unit tests.
 */
final class TwoFactorTestKit
{
    public static function makeUser(): User
    {
        return User::make(['uid' => 42, 'name' => 'alice', 'mail' => 'alice@example.com']);
    }

    public static function makeService(?EntityTypeManagerInterface $manager = null): TwoFactorService
    {
        return new TwoFactorService(new TwoFactorManager(), $manager ?? self::makeEntityTypeManager());
    }

    /**
     * @param array<int, User> $usersById Optional preloaded users (load() returns them).
     */
    public static function makeEntityTypeManager(array $usersById = []): EntityTypeManagerInterface
    {
        $storage = new class ($usersById) implements EntityStorageInterface {
            /** @param array<int, User> $usersById */
            public function __construct(private array $usersById) {}
            public function create(array $values = []): EntityInterface { throw new \BadMethodCallException(); }
            public function load(int|string $id): ?EntityInterface { return $this->usersById[(int) $id] ?? null; }
            public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }
            public function loadMultiple(array $ids = []): array { return []; }
            public function save(EntityInterface $entity): int { return 2; }
            public function delete(array $entities): void {}
            public function getQuery(): EntityQueryInterface { throw new \BadMethodCallException(); }
            public function getEntityTypeId(): string { return 'user'; }
        };
        return new class ($storage) implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function __construct(private readonly EntityStorageInterface $storage) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { throw new \BadMethodCallException(); }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function hasDefinition(string $entityTypeId): bool { return true; }
            public function getDefinitions(): array { return []; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { return $this->storage; }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface { throw new \BadMethodCallException(); }
        };
    }

    /** @param array<string,mixed> $body */
    public static function makeAuthenticatedRequest(?User $user, array $body = []): Request
    {
        $request = Request::create('/auth/2fa/x', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        if ($user !== null) {
            $request->attributes->set('_account', $user);
        }
        return $request;
    }

    public static function makeRateLimiter(bool $limited = false): RateLimiterInterface
    {
        return new class ($limited) implements RateLimiterInterface {
            /** @var list<array{key:string, ttl:int}> */
            public array $hits = [];
            public function __construct(public bool $limited) {}
            public function hit(string $key, int $decaySeconds): void { $this->hits[] = ['key' => $key, 'ttl' => $decaySeconds]; }
            public function tooManyAttempts(string $key, int $maxAttempts): bool { return $this->limited; }
            public function attempts(string $key): int { return 0; }
            public function remaining(string $key, int $maxAttempts): int { return $maxAttempts; }
            public function clear(string $key): void {}
        };
    }
}
