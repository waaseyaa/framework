<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Path\PathAliasAccessPolicy;

#[CoversClass(PathAliasAccessPolicy::class)]
final class PathAliasAccessPolicyTest extends TestCase
{
    private PathAliasAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PathAliasAccessPolicy();
    }

    #[Test]
    public function has_policy_attribute_for_path_alias(): void
    {
        $ref = new \ReflectionClass(PathAliasAccessPolicy::class);
        $attrs = $ref->getAttributes(PolicyAttribute::class);

        $this->assertNotEmpty($attrs, 'PathAliasAccessPolicy must have #[PolicyAttribute] for auto-discovery.');
        $this->assertContains('path_alias', $attrs[0]->newInstance()->entityTypes);
    }

    #[Test]
    public function implements_access_policy_interface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    #[Test]
    public function applies_to_path_alias(): void
    {
        $this->assertTrue($this->policy->appliesTo('path_alias'));
        $this->assertFalse($this->policy->appliesTo('node'));
    }

    #[Test]
    public function anyone_can_view_a_path_alias(): void
    {
        $entity = $this->makeEntity();
        $account = $this->makeAccount([]);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function anonymous_user_can_view_path_aliases(): void
    {
        // Path aliases are publicly viewable — even unauthenticated users can resolve them.
        $entity = $this->makeEntity();
        $account = $this->makeAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function edit_requires_administer_url_aliases(): void
    {
        $entity = $this->makeEntity();

        $withPerm = $this->makeAccount(['administer url aliases']);
        $this->assertTrue($this->policy->access($entity, 'update', $withPerm)->isAllowed());

        $noPerm = $this->makeAccount([]);
        $this->assertFalse($this->policy->access($entity, 'update', $noPerm)->isAllowed());
    }

    #[Test]
    public function delete_requires_administer_url_aliases(): void
    {
        $entity = $this->makeEntity();

        $withPerm = $this->makeAccount(['administer url aliases']);
        $this->assertTrue($this->policy->access($entity, 'delete', $withPerm)->isAllowed());

        $noPerm = $this->makeAccount([]);
        $this->assertFalse($this->policy->access($entity, 'delete', $noPerm)->isAllowed());
    }

    #[Test]
    public function create_access_requires_administer_url_aliases(): void
    {
        $withPerm = $this->makeAccount(['administer url aliases']);
        $this->assertTrue($this->policy->createAccess('path_alias', 'default', $withPerm)->isAllowed());

        $noPerm = $this->makeAccount([]);
        $this->assertFalse($this->policy->createAccess('path_alias', 'default', $noPerm)->isAllowed());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEntity(): EntityInterface
    {
        return new class implements EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return ''; }
            public function label(): string { return '/about'; }
            public function getEntityTypeId(): string { return 'path_alias'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function toArray(): array { return []; }
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
