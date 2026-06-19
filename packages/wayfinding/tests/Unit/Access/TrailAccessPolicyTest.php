<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Wayfinding\Access\TrailAccessPolicy;
use Waaseyaa\Wayfinding\Entity\Trail;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * The saved-trail access policy: a trail becomes human-owned on save, so only
 * its owner edits/deletes it, while the "present guided content" capability
 * gates creation and (with ownership) viewing (LD-2/LD-5).
 */
#[CoversClass(TrailAccessPolicy::class)]
final class TrailAccessPolicyTest extends TestCase
{
    private TrailAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TrailAccessPolicy();
    }

    private function trailOwnedBy(int $ownerUid): Trail
    {
        return new Trail(['owner_uid' => $ownerUid]);
    }

    private function account(int|string $id, bool $hasCapability = false): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn((int) $id > 0);
        $account->method('getRoles')->willReturn([]);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === EmitBeaconController::CAPABILITY,
        );

        return $account;
    }

    #[Test]
    public function applies_only_to_the_trail_entity_type(): void
    {
        $this->assertTrue($this->policy->appliesTo('wayfinding_trail'));
        $this->assertFalse($this->policy->appliesTo('node'));
    }

    #[Test]
    public function owner_may_view_update_and_delete(): void
    {
        $trail = $this->trailOwnedBy(7);
        $owner = $this->account(7);

        $this->assertTrue($this->policy->access($trail, 'view', $owner)->isAllowed());
        $this->assertTrue($this->policy->access($trail, 'update', $owner)->isAllowed());
        $this->assertTrue($this->policy->access($trail, 'delete', $owner)->isAllowed());
    }

    #[Test]
    public function non_owner_is_forbidden_from_editing_or_deleting(): void
    {
        $trail = $this->trailOwnedBy(7);
        $other = $this->account(8, hasCapability: true);

        $this->assertTrue($this->policy->access($trail, 'update', $other)->isForbidden());
        $this->assertTrue($this->policy->access($trail, 'delete', $other)->isForbidden());
        // …but a capability holder may still view.
        $this->assertTrue($this->policy->access($trail, 'view', $other)->isAllowed());
    }

    #[Test]
    public function anonymous_account_is_neither_owner_nor_capable(): void
    {
        $trail = $this->trailOwnedBy(7);
        $anon = $this->account(0);

        $this->assertTrue($this->policy->access($trail, 'view', $anon)->isNeutral());
        $this->assertTrue($this->policy->access($trail, 'update', $anon)->isForbidden());
        $this->assertTrue($this->policy->createAccess('wayfinding_trail', '', $anon)->isNeutral());
    }

    #[Test]
    public function capability_holder_may_create(): void
    {
        $capable = $this->account(9, hasCapability: true);
        $plain = $this->account(9);

        $this->assertTrue($this->policy->createAccess('wayfinding_trail', '', $capable)->isAllowed());
        $this->assertTrue($this->policy->createAccess('wayfinding_trail', '', $plain)->isNeutral());
    }

    #[Test]
    public function store_managed_fields_are_not_directly_editable(): void
    {
        $trail = $this->trailOwnedBy(7);
        $owner = $this->account(7);

        $this->assertTrue($this->policy->fieldAccess($trail, 'owner_uid', 'edit', $owner)->isForbidden());
        $this->assertTrue($this->policy->fieldAccess($trail, 'origin', 'edit', $owner)->isForbidden());
        // Content fields stay open (neutral) for the owner.
        $this->assertTrue($this->policy->fieldAccess($trail, 'title', 'edit', $owner)->isNeutral());
        $this->assertTrue($this->policy->fieldAccess($trail, 'beacons', 'edit', $owner)->isNeutral());
    }
}
