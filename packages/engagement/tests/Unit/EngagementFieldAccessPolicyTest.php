<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Engagement\EngagementAccessPolicy;
use Waaseyaa\Entity\EntityInterface;

/**
 * Security: EngagementAccessPolicy must forbid EDIT of `user_id` on
 * reaction/comment/follow entities for non-admins, so the JSON:API write
 * path (which runs checkFieldAccess('edit') open-by-default — only an
 * explicit Forbidden denies) cannot mass-assign ownership.
 *
 * Before this policy, `user_id` was client-writable with no field policy at
 * all: an authenticated attacker could POST a comment/reaction/follow with
 * `user_id: 0`, minting a row "owned" by the anonymous account (or with any
 * other account's id, minting a row falsely attributed to a stranger).
 * Combined with the (separately fixed) missing `isAuthenticated()` guard in
 * `EngagementAccessPolicy::isOwner()`, a user_id=0 row was previously
 * DELETE-able and unpublished-viewable by every anonymous visitor.
 *
 * Mirrors `NodeFieldAccessPolicyTest`, but engagement's `user_id` is
 * stricter than node's `uid`: it is immutable outright once the row exists
 * (never reassignable, not even by an admin-adjacent "own" permission —
 * only `administer content` bypasses), and at CREATE it is settable only to
 * the caller's own account id.
 */
#[CoversClass(EngagementAccessPolicy::class)]
final class EngagementFieldAccessPolicyTest extends TestCase
{
    private EngagementAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new EngagementAccessPolicy();
    }

    /** @param list<string> $permissions */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn($id !== 0);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => in_array($permission, $permissions, true),
        );

        return $account;
    }

    private function existingEntity(int $ownerId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('isNew')->willReturn(false);
        $entity->method('get')->with('user_id')->willReturn($ownerId);

        return $entity;
    }

    private function newEntity(int $submittedUserId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('get')->with('user_id')->willReturn($submittedUserId);

        return $entity;
    }

    #[Test]
    public function implements_field_access_policy_interface(): void
    {
        $this->assertInstanceOf(FieldAccessPolicyInterface::class, $this->policy);
    }

    #[Test]
    public function non_admin_cannot_reassign_user_id_on_existing_entity(): void
    {
        $entity = $this->existingEntity(ownerId: 5);
        $account = $this->createAccount(5, []);

        $result = $this->policy->fieldAccess($entity, 'user_id', 'edit', $account);

        $this->assertTrue($result->isForbidden(), 'user_id on an existing engagement entity must be immutable.');
    }

    #[Test]
    public function non_admin_cannot_create_row_owned_by_anonymous_zero(): void
    {
        // The exact exploit: an authenticated attacker (id 5) submits
        // user_id: 0 to mint a row "owned" by anonymous.
        $entity = $this->newEntity(submittedUserId: 0);
        $account = $this->createAccount(5, []);

        $result = $this->policy->fieldAccess($entity, 'user_id', 'edit', $account);

        $this->assertTrue($result->isForbidden(), 'A non-admin must not be able to create a row owned by anonymous (user_id 0).');
    }

    #[Test]
    public function non_admin_cannot_create_row_owned_by_someone_else(): void
    {
        $entity = $this->newEntity(submittedUserId: 999);
        $account = $this->createAccount(5, []);

        $result = $this->policy->fieldAccess($entity, 'user_id', 'edit', $account);

        $this->assertTrue($result->isForbidden(), 'A non-admin must not be able to create a row owned by another account.');
    }

    #[Test]
    public function non_admin_can_create_self_owned_row(): void
    {
        $entity = $this->newEntity(submittedUserId: 5);
        $account = $this->createAccount(5, []);

        $result = $this->policy->fieldAccess($entity, 'user_id', 'edit', $account);

        $this->assertFalse($result->isForbidden(), 'Self-owned creation is the only legitimate case and must not be forbidden.');
    }

    #[Test]
    public function admin_may_edit_user_id(): void
    {
        $entity = $this->existingEntity(ownerId: 5);
        $admin = $this->createAccount(9, ['administer content']);

        $result = $this->policy->fieldAccess($entity, 'user_id', 'edit', $admin);

        $this->assertFalse($result->isForbidden(), "'administer content' must bypass the user_id lock.");
    }

    #[Test]
    public function field_gate_ignores_view_and_other_fields(): void
    {
        $account = $this->createAccount(5, []);

        $existing = $this->existingEntity(ownerId: 42);
        $viewResult = $this->policy->fieldAccess($existing, 'user_id', 'view', $account);
        $this->assertFalse($viewResult->isForbidden(), 'The field gate restricts edit only.');

        $bodyResult = $this->policy->fieldAccess($existing, 'body', 'edit', $account);
        $this->assertFalse($bodyResult->isForbidden(), 'Fields other than user_id are not restricted by this gate.');
    }
}
