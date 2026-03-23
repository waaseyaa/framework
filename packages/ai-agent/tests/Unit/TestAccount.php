<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use Waaseyaa\Access\AccountInterface;

/**
 * Simple test account for unit testing purposes.
 */
final class TestAccount implements AccountInterface
{
    public function __construct(
        private readonly int $id = 1,
    ) {}

    public function id(): int|string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['authenticated'];
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
