<?php

declare(strict_types=1);

namespace Waaseyaa\Menu\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Menu\MenuAccessPolicy;

#[CoversClass(MenuAccessPolicy::class)]
final class MenuAccessPolicyTest extends TestCase
{
    private MenuAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MenuAccessPolicy();
    }

    #[Test]
    public function has_policy_attribute_for_menu_entity_types(): void
    {
        $ref = new \ReflectionClass(MenuAccessPolicy::class);
        $attrs = $ref->getAttributes(PolicyAttribute::class);

        $this->assertNotEmpty($attrs, 'MenuAccessPolicy must have #[PolicyAttribute] for auto-discovery.');
        $entityTypes = $attrs[0]->newInstance()->entityTypes;
        $this->assertContains('menu', $entityTypes);
        $this->assertContains('menu_link', $entityTypes);
    }

    #[Test]
    public function implements_access_policy_interface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    #[Test]
    public function applies_to_menu_and_menu_link(): void
    {
        $this->assertTrue($this->policy->appliesTo('menu'));
        $this->assertTrue($this->policy->appliesTo('menu_link'));
        $this->assertFalse($this->policy->appliesTo('node'));
    }

    #[Test]
    public function admin_can_view_menus(): void
    {
        $entity = $this->makeEntity('menu');
        $account = $this->makeAccount(['administer menu']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function authenticated_user_can_view_menus(): void
    {
        $entity = $this->makeEntity('menu');
        $account = $this->makeAccount(['access content']);

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function anonymous_user_cannot_view_menus(): void
    {
        $entity = $this->makeEntity('menu');
        $account = $this->makeAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        $this->assertFalse($result->isAllowed());
    }

    #[Test]
    public function edit_requires_permission(): void
    {
        $entity = $this->makeEntity('menu');

        $withPerm = $this->makeAccount(['administer menu']);
        $this->assertTrue($this->policy->access($entity, 'update', $withPerm)->isAllowed());

        $noPerm = $this->makeAccount([]);
        $this->assertFalse($this->policy->access($entity, 'update', $noPerm)->isAllowed());
    }

    #[Test]
    public function create_access_requires_administer_menu(): void
    {
        $withPerm = $this->makeAccount(['administer menu']);
        $this->assertTrue($this->policy->createAccess('menu', 'default', $withPerm)->isAllowed());

        $noPerm = $this->makeAccount([]);
        $this->assertFalse($this->policy->createAccess('menu', 'default', $noPerm)->isAllowed());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEntity(string $type): EntityInterface
    {
        return new class($type) implements EntityInterface {
            public function __construct(private readonly string $type) {}
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return $this->type; }
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
