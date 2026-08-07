<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeAccessPolicy::class)]
final class NodeAccessPolicyTest extends TestCase
{
    private NodeAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new NodeAccessPolicy();
    }

    // -----------------------------------------------------------------
    // Interface and appliesTo
    // -----------------------------------------------------------------

    public function testImplementsAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(NodeAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAppliesToNode(): void
    {
        $this->assertTrue($this->policy->appliesTo('node'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo('taxonomy_term'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // -----------------------------------------------------------------
    // View: Published node
    // -----------------------------------------------------------------

    public function testViewPublishedNodeWithPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 1, 'uid' => 5]);
        $account = $this->createAccount(10, ['access content']);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testViewPublishedNodeWithoutPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 1, 'uid' => 5]);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // View: Unpublished node
    // -----------------------------------------------------------------

    public function testViewUnpublishedNodeAsAuthorWithPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 0, 'uid' => 5]);
        $account = $this->createAccount(5, ['view own unpublished content']);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testAnonymousIsNotOwnerOfAuthorlessUnpublishedNode(): void
    {
        // Authorless node: getAuthorId() = (int) (null) = 0, which equals the
        // anonymous account's id() = 0. Pre-fix `$account->id() === getAuthorId()`
        // made anonymous the "owner", so an anon granted 'view own unpublished
        // content' could read every authorless draft. An unauthenticated account
        // must never be considered an owner.
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 0]); // no uid
        $account = $this->createAccount(0, ['access content', 'view own unpublished content']);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isNeutral(), 'anonymous must not be owner of an authorless node');
    }

    public function testAnonymousCannotEditAuthorlessNodeViaOwnPermission(): void
    {
        // Same degenerate ownership for the mutating path: anon granted
        // 'edit own article content' must not be able to edit an authorless node.
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 1]); // no uid
        $account = $this->createAccount(0, ['edit own article content']);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isNeutral(), 'anonymous must not edit an authorless node as "owner"');
    }

    public function testViewUnpublishedNodeAsAuthorWithoutPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 0, 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testViewUnpublishedNodeAsNonAuthorWithPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 0, 'uid' => 5]);
        $account = $this->createAccount(10, ['view own unpublished content']);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testViewUnpublishedNodeAsAdministrator(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'status' => 0, 'uid' => 5]);
        $account = $this->createAccount(1, ['administer nodes']);

        $result = $this->policy->access($node, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update (edit)
    // -----------------------------------------------------------------

    public function testEditOwnContent(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(5, ['edit own article content']);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testEditOwnContentWithoutPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testEditAnyContent(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(10, ['edit any article content']);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testEditOtherUserContentWithoutAnyPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(10, ['edit own article content']);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testEditWithAdminPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(1, ['administer nodes']);

        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function testDeleteOwnContent(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'page', 'uid' => 5]);
        $account = $this->createAccount(5, ['delete own page content']);

        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteOwnContentWithoutPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'page', 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteAnyContent(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'page', 'uid' => 5]);
        $account = $this->createAccount(10, ['delete any page content']);

        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteOtherUserContentWithoutAnyPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'page', 'uid' => 5]);
        $account = $this->createAccount(10, ['delete own page content']);

        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteWithAdminPermission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'page', 'uid' => 5]);
        $account = $this->createAccount(1, ['administer nodes']);

        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Create access
    // -----------------------------------------------------------------

    public function testCreateAccessWithPermission(): void
    {
        $account = $this->createAccount(5, ['create article content']);

        $result = $this->policy->createAccess('node', 'article', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWithoutPermission(): void
    {
        $account = $this->createAccount(5, []);

        $result = $this->policy->createAccess('node', 'article', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessWithAdminPermission(): void
    {
        $account = $this->createAccount(1, ['administer nodes']);

        $result = $this->policy->createAccess('node', 'article', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWrongBundlePermission(): void
    {
        $account = $this->createAccount(5, ['create page content']);

        $result = $this->policy->createAccess('node', 'article', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Unknown operation
    // -----------------------------------------------------------------

    public function testUnknownOperationReturnsNeutral(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(10, ['access content']);

        $result = $this->policy->access($node, 'unknown_op', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Type-specific permissions
    // -----------------------------------------------------------------

    public function testEditPermissionIsTypeSpecific(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(5, ['edit own page content']);

        // Has permission for 'page' but node is 'article'.
        $result = $this->policy->access($node, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeletePermissionIsTypeSpecific(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 5]);
        $account = $this->createAccount(5, ['delete own page content']);

        // Has permission for 'page' but node is 'article'.
        $result = $this->policy->access($node, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /**
     * Creates a mock AccountInterface.
     *
     * @param int $id The account ID.
     * @param string[] $permissions The permissions this account has.
     */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn($id);
        // Account id 0 is the anonymous account (not authenticated).
        $account->method('isAuthenticated')->willReturn($id !== 0);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }
}
