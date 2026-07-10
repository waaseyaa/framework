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
 * manage + create on every entity type in the `content` group (this already
 * covers `node` — `NodeAccessPolicy::createAccess()` never returns
 * `Forbidden`, so it never overrides the group-wide grant; `node`'s own
 * `administer nodes` permission is not additionally required). Apps
 * importing into entity types OUTSIDE the `content` group need a separate
 * admin permission, because the corresponding policy never even runs for
 * `administer content` holders (`AccessPolicyInterface::appliesTo()` is
 * scoped by group). A WordPress-shaped import (posts → `node`, terms →
 * `taxonomy_term`, attachments → `media`) needs the trio
 * `['administer content', 'administer taxonomy', 'administer media']` —
 * `'administer taxonomy'` (`Waaseyaa\Taxonomy\TermAccessPolicy`) and
 * `'administer media'` (`Waaseyaa\Media\MediaAccessPolicy`) are the exact
 * strings those policies check. `DEFAULT_PERMISSIONS` stays a floor for the
 * common content-only case; construct with the extra permissions your
 * migration's destination entity types require.
 *
 * **Per-bundle create permissions do not work through this account, or any
 * account, on the import path.** `EntityAccessGate::allows('create', ...)`
 * (`packages/access/src/Gate/EntityAccessGate.php`) hardcodes bundle `''`
 * when it calls `checkCreateAccess()` — see that method's own `@todo`. A
 * permission like `'create article content'`, `'create terms in tags'`, or a
 * per-bundle media create permission can therefore NEVER match through the
 * gate `EntityDestination` consults; only entity-type/group-wide admin
 * permissions (`administer content` / `administer taxonomy` /
 * `administer media`, or a hand-written policy with no bundle check) grant
 * import writes. This is fixed only if/when `GateInterface` grows a
 * bundle-aware create subject.
 *
 * **Never install this account into the kernel `AccountContext`.** Callers
 * such as `EntityRepository::resolveActor()`
 * (`packages/entity-storage/src/EntityRepository.php`) and audit listeners
 * cast the ambient account's `id()` to `int` for `revision_author`/actor
 * attribution. `id()` here returns the string sentinel `'migration:system'`;
 * `(int) 'migration:system'` is `0`, which collides with
 * `Waaseyaa\User\AnonymousUser`'s id `0` — a migration run whose account
 * context is this class would silently attribute every write to the
 * anonymous sentinel. Pass this account only as `EntityDestination`'s
 * `$account` constructor argument (consulted directly by the gate, never
 * cast to int), not as the ambient acting account.
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
