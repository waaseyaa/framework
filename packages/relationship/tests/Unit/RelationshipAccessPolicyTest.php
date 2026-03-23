<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Relationship\RelationshipAccessPolicy;

#[CoversClass(RelationshipAccessPolicy::class)]
final class RelationshipAccessPolicyTest extends TestCase
{
    private RelationshipAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RelationshipAccessPolicy();
    }

    #[Test]
    public function has_policy_attribute_for_discovery(): void
    {
        $ref = new \ReflectionClass(RelationshipAccessPolicy::class);
        $attrs = $ref->getAttributes(PolicyAttribute::class);

        $this->assertNotEmpty($attrs, 'RelationshipAccessPolicy must have #[PolicyAttribute] for auto-discovery.');
        $this->assertContains('relationship', $attrs[0]->newInstance()->entityTypes);
    }

    #[Test]
    public function implements_access_policy_interface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    #[Test]
    public function applies_to_relationship_entity_type(): void
    {
        $this->assertTrue($this->policy->appliesTo('relationship'));
        $this->assertFalse($this->policy->appliesTo('node'));
    }

    #[Test]
    public function admin_can_view_any_relationship(): void
    {
        $entity = $this->makeEntity(['status' => 1]);
        $account = $this->makeAccount(['administer nodes']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function published_relationship_is_viewable_with_access_content_permission(): void
    {
        $entity = $this->makeEntity(['status' => 1]);
        $account = $this->makeAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function unpublished_relationship_is_not_viewable_without_admin(): void
    {
        $entity = $this->makeEntity(['status' => 0]);
        $account = $this->makeAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function create_access_requires_permission(): void
    {
        $account = $this->makeAccount(['create relationship content']);
        $result = $this->policy->createAccess('relationship', 'default', $account);
        $this->assertTrue($result->isAllowed());

        $noPerms = $this->makeAccount([]);
        $denied = $this->policy->createAccess('relationship', 'default', $noPerms);
        $this->assertFalse($denied->isAllowed());
    }

    #[Test]
    public function anonymous_user_cannot_view_published_relationship(): void
    {
        // Anonymous users have no permissions, so access_content check fails.
        $entity = $this->makeEntity(['status' => 1]);
        $account = $this->makeAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertFalse($result->isAllowed());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEntity(array $values): EntityInterface
    {
        return new class($values) implements EntityInterface {
            public function __construct(private readonly array $values) {}
            public function id(): int|string|null { return $this->values['id'] ?? 1; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'relationship'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
        };
    }

    private function makeAccount(array $permissions): AccountInterface
    {
        return new class($permissions) implements AccountInterface {
            public function __construct(private readonly array $permissions) {}
            public function id(): int|string { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
        };
    }

    private function makeAnonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string { return 0; }
            public function isAuthenticated(): bool { return false; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return []; }
        };
    }
}
