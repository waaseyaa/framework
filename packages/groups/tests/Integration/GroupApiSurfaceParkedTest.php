<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;

/**
 * Pins the PARK decision for group/group_type's JSON:API surface
 * (audit-remediation batch 2026-07-02, WP5; tracked in issue #1871).
 *
 * `waaseyaa/groups` registers `group` and `group_type` for storage/bundle
 * purposes only. It ships zero `AccessPolicyInterface` implementations, and
 * `JsonApiRouteProvider` auto-mounts JSON:API CRUD routes for every
 * registered entity type with no per-type opt-out (that structural gap is
 * only closeable by the planned entity-type `api:` exposure flag — not built
 * yet). The deliberate consequence is that `/api/group*` CRUD denies for
 * every account, including admins, because `EntityAccessHandler` has no
 * policy to consult and treats Neutral as denied.
 *
 * This test exists so that if someone later adds an access policy for
 * group/group_type, or flips `discoverable` back to true, they hit a failing
 * assertion here and must consciously update it — the parked state stops
 * being an implicit accident and becomes something a change has to justify.
 *
 * See also: packages/groups/README.md "API exposure (parked)" and the
 * PARKED API surface doc block on GroupsServiceProvider::register().
 */
#[CoversNothing]
final class GroupApiSurfaceParkedTest extends TestCase
{
    #[Test]
    public function groupAndGroupTypeAreNotDiscoverable(): void
    {
        $types = $this->registeredTypes();

        self::assertArrayHasKey('group', $types);
        self::assertArrayHasKey('group_type', $types);

        self::assertFalse(
            $types['group']->isDiscoverable(),
            'group must be discoverable:false — it is de-advertised from GET /api pending #1871.',
        );
        self::assertFalse(
            $types['group_type']->isDiscoverable(),
            'group_type must be discoverable:false — it is de-advertised from GET /api pending #1871.',
        );
    }

    #[Test]
    public function noAccessPolicyIsRegisteredForGroupOrGroupType(): void
    {
        // GroupsServiceProvider::register() only calls $this->entityType(...)
        // for 'group' and 'group_type' — it never binds anything against
        // AccessPolicyInterface. Mirroring that here with an explicitly empty
        // policy list (rather than pulling in a full container) documents the
        // real-world state: nothing in this package opines on group access.
        $handler = new EntityAccessHandler([]);

        $group = $this->makeGroup();
        $account = new AnonymousUser();

        self::assertTrue(
            $handler->check($group, 'view', $account)->isNeutral(),
            'With zero registered policies, group access must be Neutral (no opinion) — '
            . 'confirming no policy is registered for "group".',
        );
    }

    #[Test]
    public function entityAccessHandlerDeniesGroupCrudForPlainAuthenticatedAccount(): void
    {
        $handler = new EntityAccessHandler([]);
        $group = $this->makeGroup();
        $account = new AnonymousUser(permissions: ['some.unrelated.permission']);

        self::assertFalse($handler->check($group, 'view', $account)->isAllowed());
        self::assertFalse($handler->check($group, 'update', $account)->isAllowed());
        self::assertFalse($handler->check($group, 'delete', $account)->isAllowed());
        self::assertFalse(
            $handler->checkCreateAccess('group', 'business', $account)->isAllowed(),
        );
    }

    #[Test]
    public function entityAccessHandlerDeniesGroupCrudForAdminAccount(): void
    {
        // Deny-by-default is not "deny unless you're not an admin" — it is
        // "deny unless a policy grants access", full stop. DevAdminAccount
        // has every permission, but EntityAccessHandler never even asks
        // hasPermission() when zero policies apply, so admins are denied
        // exactly like anonymous/authenticated accounts. This is the crux of
        // the fail-closed-for-everyone finding that motivated the PARK
        // decision.
        $handler = new EntityAccessHandler([]);
        $group = $this->makeGroup();
        $account = new DevAdminAccount();

        self::assertFalse($handler->check($group, 'view', $account)->isAllowed());
        self::assertFalse($handler->check($group, 'update', $account)->isAllowed());
        self::assertFalse($handler->check($group, 'delete', $account)->isAllowed());
        self::assertFalse(
            $handler->checkCreateAccess('group', 'business', $account)->isAllowed(),
        );
    }

    /**
     * @return array<string, EntityTypeInterface>
     */
    private function registeredTypes(): array
    {
        $provider = new GroupsServiceProvider();
        $provider->register();

        $types = [];
        foreach ($provider->getEntityTypes() as $type) {
            $types[$type->id()] = $type;
        }

        return $types;
    }

    private function makeGroup(): Group
    {
        return new Group([
            'uuid' => 'uuid-parked-surface-test',
            'type' => 'business',
            'name' => 'Parked Surface Test Group',
            'langcode' => 'en',
        ]);
    }
}
