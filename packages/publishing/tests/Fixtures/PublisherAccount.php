<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Value-object principal holding an explicit permission list. */
final readonly class PublisherAccount implements AuthorizationPrincipalInterface
{
    /** @param list<string> $permissions */
    public function __construct(
        private int $uid = 900001,
        private array $permissions = [],
    ) {}

    public function id(): int
    {
        return $this->uid;
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return ['content_publisher'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function claimsGeneration(): string
    {
        return 'test';
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
