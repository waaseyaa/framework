<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Field\Classification\RoleBasedClearanceChecker;

#[CoversClass(RoleBasedClearanceChecker::class)]
final class RoleBasedClearanceCheckerTest extends TestCase
{
    #[Test]
    public function default_mapping_matches_spec_default(): void
    {
        self::assertSame(
            ['admin' => 10, 'nation-steward' => 9, 'editor' => 5, 'viewer' => 1, 'anonymous' => 0],
            RoleBasedClearanceChecker::DEFAULT_ROLE_CLEARANCE,
        );
    }

    #[Test]
    public function admin_role_maps_to_level_ten_by_default(): void
    {
        $checker = new RoleBasedClearanceChecker();

        self::assertSame(10, $checker->clearanceLevelFor($this->account(['admin'])));
    }

    #[Test]
    public function editor_role_maps_to_level_five_by_default(): void
    {
        $checker = new RoleBasedClearanceChecker();

        self::assertSame(5, $checker->clearanceLevelFor($this->account(['editor'])));
    }

    #[Test]
    public function anonymous_account_resolves_to_zero(): void
    {
        $checker = new RoleBasedClearanceChecker();

        self::assertSame(0, $checker->clearanceLevelFor($this->account(['anonymous'])));
        self::assertSame(0, $checker->clearanceLevelFor($this->account([])));
    }

    #[Test]
    public function unknown_role_contributes_zero(): void
    {
        $checker = new RoleBasedClearanceChecker();

        self::assertSame(0, $checker->clearanceLevelFor($this->account(['legal-counsel'])));
    }

    #[Test]
    public function multi_role_account_uses_max_matching_level(): void
    {
        $checker = new RoleBasedClearanceChecker();

        // editor (5) + viewer (1) → 5 wins
        self::assertSame(5, $checker->clearanceLevelFor($this->account(['editor', 'viewer'])));
        // admin (10) + editor (5) → 10 wins
        self::assertSame(10, $checker->clearanceLevelFor($this->account(['editor', 'admin'])));
        // unknown role does not depress matching roles' contribution
        self::assertSame(9, $checker->clearanceLevelFor($this->account(['unknown', 'nation-steward'])));
    }

    #[Test]
    public function custom_mapping_overrides_default(): void
    {
        // Verifies FR-006: nations may override the mapping via config.
        $checker = new RoleBasedClearanceChecker([
            'super-admin' => 99,
            'editor' => 7,
        ]);

        self::assertSame(99, $checker->clearanceLevelFor($this->account(['super-admin'])));
        self::assertSame(7, $checker->clearanceLevelFor($this->account(['editor'])));
        // The default `admin` role is NOT present in this override; therefore 0.
        self::assertSame(0, $checker->clearanceLevelFor($this->account(['admin'])));
    }

    #[Test]
    public function malformed_mapping_entries_are_dropped(): void
    {
        // Defensive: tolerate config drift (e.g. numeric keys, empty strings)
        // by silently dropping invalid entries rather than crashing.
        $checker = new RoleBasedClearanceChecker([
            'admin' => 10,
            '' => 5,        // empty key dropped
            42 => 'rogue',  // numeric key dropped
            'editor' => '5', // string value coerced to int
        ]);

        self::assertSame(10, $checker->clearanceLevelFor($this->account(['admin'])));
        self::assertSame(5, $checker->clearanceLevelFor($this->account(['editor'])));
    }

    /**
     * @param list<string> $roles
     */
    private function account(array $roles): AccountInterface
    {
        return new class($roles) implements AccountInterface {
            /** @param list<string> $roles */
            public function __construct(private readonly array $roles) {}

            public function id(): int
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
