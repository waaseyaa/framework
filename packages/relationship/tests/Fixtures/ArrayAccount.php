<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Access\AccountInterface;

/**
 * Minimal {@see AccountInterface} with a fixed id and permission set.
 *
 * The relationship package depends on `waaseyaa/access` (which owns
 * {@see AccountInterface}) but NOT on `waaseyaa/user`, so tests use this local
 * account rather than `Waaseyaa\User\AnonymousUser` — keeping the test suite
 * within the package's declared dependency boundary.
 *
 * @internal Test double for Relationship package tests.
 */
final class ArrayAccount implements AccountInterface
{
    /** @param list<string> $permissions */
    public function __construct(
        private readonly int $id = 0,
        private readonly array $permissions = [],
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->id === 0 ? ['anonymous'] : ['authenticated'];
    }

    public function isAuthenticated(): bool
    {
        return $this->id !== 0;
    }
}
