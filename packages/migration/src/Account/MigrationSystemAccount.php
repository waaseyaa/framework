<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Account;

use Waaseyaa\Access\AccountInterface;

/**
 * First-class, least-privilege system account for production migration runs.
 *
 * `EntityDestination` (FR-020) consults the access gate on every create /
 * update / delete, and the only account previously able to pass that gate
 * outside interactive request context was `Waaseyaa\User\DevAdminAccount`
 * (`packages/user/src/DevAdminAccount.php`) — a dev-only sentinel whose
 * constructor throws outside `cli`/`cli-server`/`frankenphp` SAPIs and whose
 * `hasPermission()` blanket-returns `true`. That left no production-safe
 * account for batch imports (G-023, Sheguiandah pass-1).
 *
 * `MigrationSystemAccount` is the opposite of `DevAdminAccount` by design:
 * `hasPermission()` is a strict membership test against an explicitly
 * injected permission list — never a blanket grant — so a migration run
 * carries exactly the permissions its destination entity types require, and
 * nothing more. It has no SAPI guard and no login surface; it is a plain,
 * never-persisted value object constructed by import wiring (e.g. a
 * `MigrationRunner` caller or CLI command) and passed as `EntityDestination`'s
 * `$account` constructor argument.
 *
 * The default permission list, `DEFAULT_PERMISSIONS`, holds exactly
 * `'administer content'` — the single permission
 * `Waaseyaa\Access\Policy\ContentAdminAccessPolicy` requires to grant
 * manage + create on every entity type in the `content` group. Apps
 * importing into entity types guarded by other policies (e.g. `node`'s
 * `administer nodes` / per-bundle `create X content` in
 * `Waaseyaa\Node\NodeAccessPolicy`) must pass the exact extra permissions
 * those policies require via the constructor — `DEFAULT_PERMISSIONS` is a
 * floor for the common content-group case, not a universal grant.
 *
 * @api
 */
final class MigrationSystemAccount implements AccountInterface
{
    /**
     * The permission `ContentAdminAccessPolicy` requires to grant manage +
     * create on content-group entity types.
     *
     * @var list<string>
     */
    public const array DEFAULT_PERMISSIONS = ['administer content'];

    /** Stable sentinel id — never collides with an integer auto-increment uid. */
    private const string ID = 'migration:system';

    /**
     * @param list<string> $permissions Permissions granted to this account. Defaults to {@see DEFAULT_PERMISSIONS}.
     */
    public function __construct(
        private readonly array $permissions = self::DEFAULT_PERMISSIONS,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['migration_system'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
