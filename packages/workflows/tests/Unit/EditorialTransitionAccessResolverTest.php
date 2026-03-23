<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowPreset;

#[CoversClass(EditorialTransitionAccessResolver::class)]
final class EditorialTransitionAccessResolverTest extends TestCase
{
    public function testAllowsAuthorizedReviewerPublishTransition(): void
    {
        $resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
        $account = new ResolverTestAccount(
            id: 7,
            permissions: ['publish article content'],
            roles: ['reviewer'],
        );

        $result = $resolver->canTransition('article', 'review', 'published', $account);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('publish article content', $resolver->requiredPermission('article', 'review', 'published'));
    }

    public function testDeniesTransitionWhenPermissionMissing(): void
    {
        $resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
        $account = new ResolverTestAccount(
            id: 7,
            permissions: [],
            roles: ['reviewer'],
        );

        $result = $resolver->canTransition('article', 'review', 'published', $account);

        $this->assertTrue($result->isForbidden());
        $this->assertStringContainsString('Required: "publish article content"', $result->reason);
    }

    public function testDeniesTransitionWhenRoleNotAuthorized(): void
    {
        $resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
        $account = new ResolverTestAccount(
            id: 7,
            permissions: ['archive article content'],
            roles: ['reviewer'],
        );

        $result = $resolver->canTransition('article', 'published', 'archived', $account);

        $this->assertTrue($result->isForbidden());
        $this->assertStringContainsString('Role not authorized for transition "archive"', $result->reason);
    }

    public function testAllowsPermissionOnlyModeWithoutRecognizedRoles(): void
    {
        $resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
        $account = new ResolverTestAccount(
            id: 7,
            permissions: ['publish article content'],
            roles: ['custom_role'],
        );

        $result = $resolver->canTransition('article', 'review', 'published', $account);

        $this->assertTrue($result->isAllowed());
    }

    public function testDeniesIllegalTransitionWithStableReason(): void
    {
        $resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
        $account = new ResolverTestAccount(
            id: 7,
            permissions: ['publish article content'],
            roles: ['editor'],
        );

        $result = $resolver->canTransition('article', 'draft', 'archived', $account);

        $this->assertTrue($result->isForbidden());
        $this->assertSame('Invalid workflow transition: draft -> archived.', $result->reason);
    }
}

final class ResolverTestAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int|string $id,
        private readonly array $permissions,
        private readonly array $roles,
    ) {}

    public function id(): int|string
    {
        return $this->id;
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
