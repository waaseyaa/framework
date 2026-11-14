<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\EngagementAccessPolicy;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;

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
    public function administrator_cannot_bypass_generic_comment_read_policy(): void
    {
        $account = $this->principal(1, true, ['administer content']);
        $entity = $this->comment();

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden(), 'Generic comment reads must use current domain policy even for administrators.');
    }

    #[Test]
    public function generic_comment_read_is_forbidden_without_current_domain_policy_even_when_parent_is_viewable(): void
    {
        $account = $this->principal(99);

        $policy = $this->policyWithParent('post', 7, parentViewable: true);
        $entity = $this->comment();

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden());
        $this->assertSame('Current engagement policy is unavailable.', $result->reason);
    }

    #[Test]
    public function generic_comment_read_is_forbidden_without_current_domain_policy_when_parent_is_hidden(): void
    {
        // A published comment on a draft post must not be visible (parent-cascade).
        $account = $this->principal(99);

        $policy = $this->policyWithParent('post', 7, parentViewable: false);
        $entity = $this->comment();

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function unpublished_comment_is_not_readable_without_current_domain_policy(): void
    {
        $account = $this->principal(99);

        // Parent IS viewable, but the comment itself is unmoderated/unpublished.
        $policy = $this->policyWithParent('post', 7, parentViewable: true);
        $entity = $this->comment(status: false, ownerId: 42);

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function unpublished_comment_owner_still_requires_current_domain_policy(): void
    {
        $account = $this->principal(42);

        $policy = $this->policyWithParent('post', 7, parentViewable: false);
        $entity = $this->comment(status: false, ownerId: 42);

        $result = $policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function protected_unpublished_comment_body_requires_current_domain_policy_even_for_owner(): void
    {
        $handler = new EntityAccessHandler([$this->policy]);
        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...)));
        $comment = new Comment(['user_id' => 42, 'target_type' => 'post', 'target_id' => 7, 'body' => 'Pending', 'status' => false]);

        try {
            $owner = new AuthorizationPrincipal(42, true, [], [], 'engagement-policy-test');
            try {
                $scope->run($owner, fn(): mixed => $comment->get('body'));
                self::fail('A missing current domain policy must not release a protected comment body to its owner.');
            } catch (\Waaseyaa\Entity\Exception\FieldReadDenied) {
                self::addToAssertionCount(1);
            }

            $outsider = new AuthorizationPrincipal(99, true, [], [], 'engagement-policy-test');
            $result = $handler->checkProtectedFieldRead(
                $outsider,
                $comment->entityStructure(),
                new \Waaseyaa\Access\CompiledPolicySubjectView(['user_id' => 42, 'target_type' => 'post', 'target_id' => 7, 'status' => false]),
                'body',
            );
            self::assertTrue($result->isForbidden());
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }

    #[Test]
    public function anonymous_cannot_view_unpublished_comment_with_user_id_zero(): void
    {
        // The exact exploit: a comment with user_id === 0 (mintable pre-fix via
        // the client-writable user_id field hole) must not be "owned" by the
        // anonymous account just because AnonymousUser::id() also returns 0.
        $account = $this->principal(0, false);

        // Parent view resolution is irrelevant: pre-fix, the owner branch
        // short-circuited to Allowed before parent-cascade was ever consulted.
        $entity = $this->comment(status: false, ownerId: 0);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden(), 'Anonymous must not be treated as the owner of a user_id=0 row.');
    }

    #[Test]
    public function view_fails_closed_without_parent_resolution_dependencies(): void
    {
        // The bare policy (no entity-type-manager / access-handler) cannot prove
        // parent visibility, so view is denied rather than leaked.
        $account = $this->principal(99);
        $entity = $this->comment();

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function follow_owner_can_delete_through_generic_access(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('id')->willReturn(42);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('follow');
        $entity->expects(self::once())->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function follow_non_owner_cannot_delete_through_generic_access(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('id')->willReturn(99);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('follow');
        $entity->expects(self::once())->method('get')->with('user_id')->willReturn(42);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function anonymous_is_never_owner_of_a_user_id_zero_follow_on_delete(): void
    {
        // The exact exploit: AnonymousUser::id() returns 0. A row with
        // user_id === 0 (mintable pre-fix via the client-writable user_id
        // field hole) must not grant anonymous DELETE just because the ids
        // collide.
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('id')->willReturn(0);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('follow');
        $entity->expects(self::once())->method('get')->with('user_id')->willReturn(0);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isNeutral(), 'Anonymous must not be granted delete on a user_id=0 row.');
    }

    /**
     * Build a policy whose entity-type-manager resolves `$type:$id` to a parent
     * entity, and whose access handler grants/denies `view` on that parent.
     */
    private function policyWithParent(string $type, int $id, bool $parentViewable): EngagementAccessPolicy
    {
        $parent = $this->createStub(EntityInterface::class);
        $parent->method('getEntityTypeId')->willReturn($type);
        $parent->method('bundle')->willReturn('');

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn($parent);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturnCallback(static fn(string $t): bool => $t === $type);
        $etm->method('getStorage')->willReturn($storage);
        // C-22 WP3: read path now goes through the canonical repository.
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $policy = $this->createStub(AccessPolicyInterface::class);
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
        $entity = $this->createStub(EntityInterface::class);
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

    private function comment(bool $status = true, int $ownerId = 1): Comment
    {
        return new Comment([
            'user_id' => $ownerId,
            'target_type' => 'post',
            'target_id' => 7,
            'root_uuid' => 'root-uuid',
            'lifecycle' => 'active',
            'body' => 'Pending or published comment',
            'status' => $status,
        ]);
    }

    /** @param list<string> $permissions */
    private function principal(int|string $id, bool $authenticated = true, array $permissions = []): AuthorizationPrincipalInterface
    {
        return new class($id, $authenticated, $permissions) implements AuthorizationPrincipalInterface {
            /** @param list<string> $permissions */
            public function __construct(private int|string $principalId, private bool $authenticated, private array $permissions) {}
            public function id(): int|string { return $this->principalId; }
            public function hasPermission(string $permission): bool { return in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return $this->authenticated; }
            public function claimsGeneration(): string { return 'engagement-policy-test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }

    #[Test]
    public function generic_reaction_and_comment_creation_is_forbidden_even_for_authenticated_users(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);

        foreach (['reaction', 'comment'] as $type) {
            $result = $this->policy->createAccess($type, $type, $account);
            $this->assertTrue($result->isForbidden());
        }
    }

    #[Test]
    public function generic_reaction_and_comment_creation_is_forbidden_for_anonymous_users(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(false);

        foreach (['reaction', 'comment'] as $type) {
            $result = $this->policy->createAccess($type, $type, $account);
            $this->assertTrue($result->isForbidden());
        }
    }

    #[Test]
    public function follow_keeps_generic_parent_view_and_creation_behavior(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);
        $policy = $this->policyWithParent('post', 7, parentViewable: true);
        $follow = $this->engagement('follow', targetType: 'post', targetId: 7);

        self::assertTrue($policy->access($follow, 'view', $account)->isAllowed());
        self::assertTrue($policy->createAccess('follow', 'follow', $account)->isAllowed());

        $anonymous = $this->createStub(AccountInterface::class);
        $anonymous->method('hasPermission')->willReturn(false);
        $anonymous->method('isAuthenticated')->willReturn(false);
        self::assertTrue($policy->createAccess('follow', 'follow', $anonymous)->isNeutral());
    }
}
