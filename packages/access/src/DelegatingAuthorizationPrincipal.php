<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Explicit migration principal for identity providers backed by a legacy
 * account implementation.
 *
 * Permission and role decisions delegate verbatim to the wrapped account;
 * the identity provider must supply the immutable claim-generation and scope
 * claims it owns. Claims metadata is frozen at construction; authorization
 * checks delegate live to the wrapped account. The framework never invents
 * these values silently.
 *
 * @api
 */
final readonly class DelegatingAuthorizationPrincipal implements AuthorizationPrincipalInterface
{
    public function __construct(
        private AccountInterface $account,
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
        return $this->account->id();
    }
    public function hasPermission(string $permission): bool
    {
        return $this->account->hasPermission($permission);
    }
    public function getRoles(): array
    {
        return $this->account->getRoles();
    }
    public function isAuthenticated(): bool
    {
        return $this->account->isAuthenticated();
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
