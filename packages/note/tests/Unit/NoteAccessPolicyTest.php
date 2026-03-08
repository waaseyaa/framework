<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
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
}
