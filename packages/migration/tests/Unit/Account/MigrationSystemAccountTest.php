<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Account;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Account\MigrationSystemAccount;

/**
 * Unit coverage for {@see MigrationSystemAccount} — the least-privilege,
 * production-safe account for migration destination writes (G-023).
 */
#[CoversClass(MigrationSystemAccount::class)]
final class MigrationSystemAccountTest extends TestCase
{
    #[Test]
    public function default_permissions_grant_administer_content_only(): void
    {
        $account = new MigrationSystemAccount();

        self::assertTrue($account->hasPermission('administer content'));
        self::assertFalse($account->hasPermission('administer nodes'));
        self::assertFalse($account->hasPermission('anything else'));
    }

    #[Test]
    public function default_permissions_constant_is_administer_content(): void
    {
        self::assertSame(['administer content'], MigrationSystemAccount::DEFAULT_PERMISSIONS);
    }

    #[Test]
    public function custom_permission_list_is_honored_strictly(): void
    {
        $account = new MigrationSystemAccount(['administer nodes', 'create article content']);

        self::assertTrue($account->hasPermission('administer nodes'));
        self::assertTrue($account->hasPermission('create article content'));
        // Not blanket-true — the default permission is NOT implicitly retained.
        self::assertFalse($account->hasPermission('administer content'));
    }

    #[Test]
    public function empty_permission_list_denies_everything(): void
    {
        $account = new MigrationSystemAccount([]);

        self::assertFalse($account->hasPermission('administer content'));
        self::assertFalse($account->hasPermission('administer nodes'));
    }

    #[Test]
    public function id_is_the_stable_string_sentinel(): void
    {
        $account = new MigrationSystemAccount();

        self::assertSame('migration:system', $account->id());
        // Semantically distinct from other sentinel accounts.
        self::assertNotSame(0, $account->id());
        self::assertNotSame(\PHP_INT_MAX, $account->id());
        self::assertIsString($account->id());
    }

    #[Test]
    public function is_authenticated_and_has_the_migration_system_role(): void
    {
        $account = new MigrationSystemAccount();

        self::assertTrue($account->isAuthenticated());
        self::assertSame(['migration_system'], $account->getRoles());
    }
}
