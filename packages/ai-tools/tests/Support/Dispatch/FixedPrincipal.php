<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** A principal that grants only what it was constructed with. */
final class FixedPrincipal implements AuthorizationPrincipalInterface
{
    /** @param list<string> $permissions */
    public function __construct(private readonly array $permissions = []) {}

    public function id(): int|string
    {
        return 'test:principal';
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function tenantId(): ?string
    {
        return null;
    }

    public function communityId(): ?string
    {
        return null;
    }

    public function claimsGeneration(): string
    {
        return 'test';
    }
}
