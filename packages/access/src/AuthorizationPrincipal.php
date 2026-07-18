<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/** @api */
final readonly class AuthorizationPrincipal implements AuthorizationPrincipalInterface
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        private int|string $accountId,
        private bool $authenticated,
        private array $roles,
        private array $permissions,
        private string $claimsGeneration,
        private ?string $tenantId = null,
        private ?string $communityId = null,
    ) {
        if ($claimsGeneration === '') {
            throw new \InvalidArgumentException('Authorization principal requires a claims generation.');
        }
    }

    public function id(): int|string
    {
        return $this->accountId;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array('administrator', $this->roles, true)
            || in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function claimsGeneration(): string
    {
        return $this->claimsGeneration;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function communityId(): ?string
    {
        return $this->communityId;
    }
}
