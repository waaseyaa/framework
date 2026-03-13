<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Note\NoteAccessPolicy;

#[CoversClass(NoteAccessPolicy::class)]
final class NoteAccessPolicyTest extends TestCase
{
    private NoteAccessPolicy $policy;
    private EntityInterface $entity;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->policy = new NoteAccessPolicy();

        $this->entity = new class implements EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return 'test-uuid'; }
            public function label(): string { return 'Test'; }
            public function bundle(): string { return ''; }
            public function getEntityTypeId(): string { return 'note'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };

        $this->account = new class implements AccountInterface {
            public function id(): int { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return []; }
        };
    }

    #[Test]
    public function appliesToNoteEntityType(): void
    {
        $this->assertTrue($this->policy->appliesTo('note'));
    }

    #[Test]
    public function doesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo('core.note'));
    }

    #[Test]
    public function deleteIsAlwaysForbidden(): void
    {
        $result = $this->policy->access($this->entity, 'delete', $this->account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function deleteIsForbiddenEvenForAdmin(): void
    {
        $admin = new class implements AccountInterface {
            public function id(): int { return PHP_INT_MAX; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['administrator']; }
        };

        $result = $this->policy->access($this->entity, 'delete', $admin);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function viewReturnsNeutral(): void
    {
        $result = $this->policy->access($this->entity, 'view', $this->account);

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function updateReturnsNeutral(): void
    {
        $result = $this->policy->access($this->entity, 'update', $this->account);

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function createAccessReturnsNeutralWithoutPermission(): void
    {
        $result = $this->policy->createAccess('note', '', $this->account);

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function createAccessAllowedWithCreateNotePermission(): void
    {
        $user = new class implements AccountInterface {
            public function id(): int { return 5; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return $permission === 'create note content'; }
            public function getRoles(): array { return ['authenticated']; }
        };

        $result = $this->policy->createAccess('note', '', $user);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function hasPolicyAttributeForNote(): void
    {
        $ref = new \ReflectionClass(NoteAccessPolicy::class);
        $attributes = $ref->getAttributes(PolicyAttribute::class);

        $this->assertCount(1, $attributes);
        $instance = $attributes[0]->newInstance();
        $this->assertContains('note', $instance->entityTypes);
    }

    #[Test]
    public function implementsFieldAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(FieldAccessPolicyInterface::class, $this->policy);
    }

    // -----------------------------------------------------------------------
    // Role-based entity access
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantMemberCanViewNote(): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->access($this->entity, 'view', $member);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function tenantAdminCanViewNote(): void
    {
        $admin = $this->accountWithRoles(['tenant.admin']);
        $result = $this->policy->access($this->entity, 'view', $admin);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function platformAdminCanViewNote(): void
    {
        $admin = $this->accountWithRoles(['platform.admin']);
        $result = $this->policy->access($this->entity, 'view', $admin);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function tenantMemberCannotUpdateNote(): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->access($this->entity, 'update', $member);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function tenantAdminCanUpdateNote(): void
    {
        $admin = $this->accountWithRoles(['tenant.admin']);
        $result = $this->policy->access($this->entity, 'update', $admin);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function platformAdminCanUpdateNote(): void
    {
        $admin = $this->accountWithRoles(['platform.admin']);
        $result = $this->policy->access($this->entity, 'update', $admin);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function deleteIsForbiddenForPlatformAdmin(): void
    {
        $admin = $this->accountWithRoles(['platform.admin']);
        $result = $this->policy->access($this->entity, 'delete', $admin);
        $this->assertTrue($result->isForbidden());
    }

    // -----------------------------------------------------------------------
    // Role-based create access
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantMemberCannotCreate(): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->createAccess('note', '', $member);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function tenantAdminCanCreate(): void
    {
        $admin = $this->accountWithRoles(['tenant.admin']);
        $result = $this->policy->createAccess('note', '', $admin);
        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function platformAdminCanCreate(): void
    {
        $admin = $this->accountWithRoles(['platform.admin']);
        $result = $this->policy->createAccess('note', '', $admin);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------------
    // Field-level access: system fields
    // -----------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function systemFieldProvider(): array
    {
        return [
            'id'         => ['id'],
            'uuid'       => ['uuid'],
            'created_at' => ['created_at'],
            'updated_at' => ['updated_at'],
        ];
    }

    #[Test]
    #[DataProvider('systemFieldProvider')]
    public function systemFieldEditIsForbiddenForTenantMember(string $field): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->fieldAccess($this->entity, $field, 'edit', $member);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    #[DataProvider('systemFieldProvider')]
    public function systemFieldEditIsForbiddenForTenantAdmin(string $field): void
    {
        $admin = $this->accountWithRoles(['tenant.admin']);
        $result = $this->policy->fieldAccess($this->entity, $field, 'edit', $admin);
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    #[DataProvider('systemFieldProvider')]
    public function systemFieldEditIsNeutralForPlatformAdmin(string $field): void
    {
        $admin = $this->accountWithRoles(['platform.admin']);
        $result = $this->policy->fieldAccess($this->entity, $field, 'edit', $admin);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    #[DataProvider('systemFieldProvider')]
    public function systemFieldViewIsNeutralForAll(string $field): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->fieldAccess($this->entity, $field, 'view', $member);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function userFieldEditIsNeutralForTenantAdmin(): void
    {
        $admin = $this->accountWithRoles(['tenant.admin']);
        $result = $this->policy->fieldAccess($this->entity, 'title', 'edit', $admin);
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function userFieldEditIsNeutralForTenantMember(): void
    {
        $member = $this->accountWithRoles(['tenant.member']);
        $result = $this->policy->fieldAccess($this->entity, 'body', 'edit', $member);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function accountWithRoles(array $roles): AccountInterface
    {
        return new class($roles) implements AccountInterface {
            public function __construct(private readonly array $roles) {}
            public function id(): int { return 42; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return $this->roles; }
        };
    }
}
