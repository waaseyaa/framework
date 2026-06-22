<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Engagement\EngagementAccessPolicy;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * @covers \Waaseyaa\Engagement\EngagementAccessPolicy
 */
#[CoversClass(EngagementAccessPolicy::class)]
final class EngagementAccessPolicyTest extends TestCase
{
    private EngagementAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new EngagementAccessPolicy();
    }

    #[Test]
    public function applies_to_engagement_types(): void
    {
        $this->assertTrue($this->policy->appliesTo('reaction'));
        $this->assertTrue($this->policy->appliesTo('comment'));
        $this->assertTrue($this->policy->appliesTo('follow'));
        $this->assertFalse($this->policy->appliesTo('post'));
    }

    #[Test]
    public function admin_is_always_allowed(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('administer content')->willReturn(true);
        $entity = $this->createMock(EntityInterface::class);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function view_cascades_allowed_when_parent_is_viewable(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);

        $policy = $this->policyWithParent('post', 7, parentViewable: true);
        $entity = $this->engagement('comment', targetType: 'post', targetId: 7, status: true);

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function view_is_denied_when_parent_is_not_viewable(): void
    {
        // A published comment on a draft post must not be visible (parent-cascade).
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);

        $policy = $this->policyWithParent('post', 7, parentViewable: false);
        $entity = $this->engagement('comment', targetType: 'post', targetId: 7, status: true);

        $result = $policy->access($entity, 'view', $account);
        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function view_of_unpublished_comment_is_denied_to_non_owner(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(99);

        // Parent IS viewable, but the comment itself is unmoderated/unpublished.
        $policy = $this->policyWithParent('post', 7, parentViewable: true);
        $entity = $this->engagement('comment', targetType: 'post', targetId: 7, status: false, ownerId: 42);

        $result = $policy->access($entity, 'view', $account);
        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function view_of_unpublished_comment_is_allowed_to_owner(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(42);

        $policy = $this->policyWithParent('post', 7, parentViewable: false);
        $entity = $this->engagement('comment', targetType: 'post', targetId: 7, status: false, ownerId: 42);

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function view_fails_closed_without_parent_resolution_dependencies(): void
    {
        // The bare policy (no entity-type-manager / access-handler) cannot prove
        // parent visibility, so view is denied rather than leaked.
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $entity = $this->engagement('comment', targetType: 'post', targetId: 7, status: true);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function owner_can_delete(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(42);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function non_owner_cannot_delete(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('id')->willReturn(99);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    /**
     * Build a policy whose entity-type-manager resolves `$type:$id` to a parent
     * entity, and whose access handler grants/denies `view` on that parent.
     */
    private function policyWithParent(string $type, int $id, bool $parentViewable): EngagementAccessPolicy
    {
        $parent = $this->createMock(EntityInterface::class);
        $parent->method('getEntityTypeId')->willReturn($type);
        $parent->method('bundle')->willReturn('');

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($parent);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturnCallback(static fn(string $t): bool => $t === $type);
        $etm->method('getStorage')->willReturn($storage);

        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturnCallback(static fn(string $t): bool => $t === $type);
        $policy->method('access')->willReturn(
            $parentViewable ? AccessResult::allowed('viewable') : AccessResult::neutral('hidden'),
        );

        return new EngagementAccessPolicy($etm, new EntityAccessHandler([$policy]));
    }

    private function engagement(
        string $type,
        string $targetType,
        int $targetId,
        bool $status = true,
        int $ownerId = 1,
    ): EntityInterface {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($type);
        $entity->method('get')->willReturnCallback(
            static fn(string $field): mixed => match ($field) {
                'target_type' => $targetType,
                'target_id' => $targetId,
                'status' => $status,
                'user_id' => $ownerId,
                default => null,
            },
        );

        return $entity;
    }

    #[Test]
    public function authenticated_can_create(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);

        $result = $this->policy->createAccess('reaction', 'reaction', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function anonymous_cannot_create(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(false);

        $result = $this->policy->createAccess('reaction', 'reaction', $account);
        $this->assertTrue($result->isNeutral());
    }
}
