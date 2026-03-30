<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Fixtures;

use Waaseyaa\Access\AccountInterface;

final class TestMcpAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int|string $userId = 0,
        private readonly array $permissions = [],
        private readonly array $roles = ['anonymous'],
        private readonly bool $authenticated = false,
    ) {}

    public function id(): int|string { return $this->userId; }
    public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
    public function getRoles(): array { return $this->roles; }
    public function isAuthenticated(): bool { return $this->authenticated; }
}
