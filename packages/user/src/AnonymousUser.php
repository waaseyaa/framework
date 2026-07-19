<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Represents a non-authenticated (anonymous) visitor.
 *
 * The anonymous user always has uid 0, is never authenticated,
 * and carries the 'anonymous' role. A fixed set of permissions
 * can be granted to anonymous visitors at construction time.
 */
final class AnonymousUser implements AuthorizationPrincipalInterface
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

    public function claimsGeneration(): string
    {
        return hash('sha256', serialize($this->permissions));
    }

    public function tenantId(): ?string
    {
        return null;
    }

    public function communityId(): ?string
    {
        return null;
    }
}
