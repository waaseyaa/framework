<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;

/**
 * Security: NodeAccessPolicy must forbid EDIT of node's system/identity fields
 * for non-administrators, so the JSON:API / agent write paths (which run
 * checkFieldAccess('edit') open-by-default — only an explicit Forbidden denies)
 * cannot mass-assign them.
 *
 * Before this policy, node declared `uid` (authorship), `type` (readOnly
 * bundle), `created`/`changed` (timestamps) as ordinary #[Field]s with NO
 * FieldAccessPolicy, so a user with `edit own {type} content` could
 * `PATCH /api/node/{id}` reassign authorship, change the bundle (despite
 * readOnly), or forge timestamps — none of which `edit own` should permit.
 *
 * Scope note: `status` and `workflow_state` are permission-gated separately
 * below (CW-v1 WP-0, `docs/specs/content-workflow.md`) — an account needs
 * `NodeAccessPolicy::PUBLISH_PERMISSION` to edit them, on both create and
 * update. `promote`/`sticky` remain intentionally NOT forbidden here pending
 * the engine.
 */
#[CoversClass(NodeAccessPolicy::class)]
final class NodeFieldAccessPolicyTest extends TestCase
{
    private NodeAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new NodeAccessPolicy();
    }

    private function node(): Node
    {
        // An EXISTING node (has an id ⇒ isNew() === false), owned by uid 5.
        return new Node(['nid' => 1, 'type' => 'article', 'status' => 1, 'uid' => 5]);
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

    #[Test]
    public function implements_field_access_policy_interface(): void
    {
        $this->assertInstanceOf(FieldAccessPolicyInterface::class, $this->policy);
    }

    /**
     * @return list<array{string}>
     */
    public static function protectedFields(): array
    {
        return [['uid'], ['type'], ['created'], ['changed']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('protectedFields')]
    public function author_cannot_edit_a_system_field(string $field): void
    {
        // The owner (uid 5) with the standard author edit permission.
        $author = $this->createAccount(5, ['edit own article content', 'access content']);

        $result = $this->policy->fieldAccess($this->node(), $field, 'edit', $author);

        $this->assertTrue(
            $result->isForbidden(),
            "Field '{$field}' must be edit-forbidden for a non-admin author",
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('protectedFields')]
    public function administrator_can_edit_a_system_field(string $field): void
    {
        $admin = $this->createAccount(9, ['administer nodes']);

        $result = $this->policy->fieldAccess($this->node(), $field, 'edit', $admin);

        $this->assertFalse(
            $result->isForbidden(),
            "Field '{$field}' must NOT be forbidden for an 'administer nodes' admin",
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('protectedFields')]
    public function system_fields_are_settable_when_creating_a_new_node(string $field): void
    {
        // At CREATE the entity is new (storage->create() enforces isNew()); the
        // bundle/authorship/timestamps are part of authoring, so the gate must
        // NOT forbid them — only mutation of an EXISTING node is restricted.
        $newNode = new Node(['type' => 'article', 'status' => 1, 'uid' => 5]);
        $newNode->enforceIsNew();
        $this->assertTrue($newNode->isNew());

        $author = $this->createAccount(5, ['create article content', 'access content']);

        $result = $this->policy->fieldAccess($newNode, $field, 'edit', $author);

        $this->assertFalse(
            $result->isForbidden(),
            "Field '{$field}' must be settable while creating a new node",
        );
    }

    #[Test]
    public function content_and_editorial_fields_stay_writable_for_the_author(): void
    {
        // CW-v1 WP-0: `status` moved out of this list — it is now
        // permission-gated below. `promote`/`sticky` remain ungated.
        $author = $this->createAccount(5, ['edit own article content', 'access content']);

        foreach (['title', 'slug', 'body', 'promote', 'sticky'] as $field) {
            $result = $this->policy->fieldAccess($this->node(), $field, 'edit', $author);
            $this->assertFalse(
                $result->isForbidden(),
                "Field '{$field}' must not be forbidden by NodeAccessPolicy field gate",
            );
        }
    }

    // -----------------------------------------------------------------
    // CW-v1 WP-0: publish gate on `status` / `workflow_state`
    // -----------------------------------------------------------------

    #[Test]
    public function status_edit_is_forbidden_without_the_publish_permission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7, 'status' => 0]);
        $account = $this->createAccount(7, ['edit own article content']);

        $result = $this->policy->fieldAccess($node, 'status', 'edit', $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function workflow_state_edit_is_forbidden_without_the_publish_permission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7]);
        $account = $this->createAccount(7, ['edit own article content']);

        $this->assertTrue($this->policy->fieldAccess($node, 'workflow_state', 'edit', $account)->isForbidden());
    }

    #[Test]
    public function status_edit_is_open_with_the_publish_permission(): void
    {
        $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7, 'status' => 0]);
        $account = $this->createAccount(7, ['edit own article content', NodeAccessPolicy::PUBLISH_PERMISSION]);

        $this->assertFalse($this->policy->fieldAccess($node, 'status', 'edit', $account)->isForbidden());
    }

    #[Test]
    public function status_gate_applies_on_create_too(): void
    {
        $node = new Node(['type' => 'article', 'uid' => 7, 'status' => 1]);
        $node->enforceIsNew();
        $account = $this->createAccount(7, ['create article content']);

        $this->assertTrue($this->policy->fieldAccess($node, 'status', 'edit', $account)->isForbidden());
    }

    #[Test]
    public function view_of_a_system_field_is_not_restricted(): void
    {
        // The field gate restricts edit only; view of these fields is unaffected.
        $reader = $this->createAccount(7, ['access content']);

        $result = $this->policy->fieldAccess($this->node(), 'uid', 'view', $reader);

        $this->assertFalse($result->isForbidden(), 'system fields stay viewable');
    }
}
