<?php

declare(strict_types=1);

namespace Aurora\User;

use Aurora\Access\AccountInterface;

/**
 * Represents a non-authenticated (anonymous) visitor.
 *
 * The anonymous user always has uid 0, is never authenticated,
 * and carries the 'anonymous' role. A fixed set of permissions
 * can be granted to anonymous visitors at construction time.
 */
final class AnonymousUser implements AccountInterface
{
    /**
     * @param string[] $permissions Permissions granted to anonymous visitors.
     */
    public function __construct(
        private readonly array $permissions = [],
    ) {}

    public function id(): int
    {
        return 0;
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['anonymous'];
    }

    public function isAuthenticated(): bool
    {
        return false;
    }
}
