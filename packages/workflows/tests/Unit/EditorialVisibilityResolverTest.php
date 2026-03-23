<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\EditorialVisibilityResolver;

#[CoversClass(EditorialVisibilityResolver::class)]
final class EditorialVisibilityResolverTest extends TestCase
{
    public function testPublishedContentIsPubliclyVisible(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 1,
            'workflow_state' => 'published',
        ]);
        $account = new VisibilityTestAccount(
            id: 0,
            permissions: [],
            roles: ['anonymous'],
            authenticated: false,
        );

        $result = $resolver->canRender($entity, $account, false);

        $this->assertTrue($result->isAllowed());
    }

    public function testDraftIsHiddenWithoutPreview(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 0,
            'workflow_state' => 'draft',
        ]);
        $account = new VisibilityTestAccount(
            id: 10,
            permissions: ['view own unpublished content'],
            roles: ['contributor'],
            authenticated: true,
        );

        $result = $resolver->canRender($entity, $account, false);

        $this->assertTrue($result->isForbidden());
        $this->assertStringContainsString('without preview', $result->reason);
    }

    public function testPreviewRequiresAuthentication(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 0,
            'workflow_state' => 'review',
        ]);
        $account = new VisibilityTestAccount(
            id: 0,
            permissions: [],
            roles: ['anonymous'],
            authenticated: false,
        );

        $result = $resolver->canRender($entity, $account, true);

        $this->assertTrue($result->isForbidden());
        $this->assertSame('Preview requires an authenticated account.', $result->reason);
    }

    public function testAuthorCanPreviewOwnUnpublished(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 0,
            'workflow_state' => 'draft',
        ]);
        $account = new VisibilityTestAccount(
            id: 10,
            permissions: ['view own unpublished content'],
            roles: ['contributor'],
            authenticated: true,
        );

        $result = $resolver->canRender($entity, $account, true);

        $this->assertTrue($result->isAllowed());
    }

    public function testReviewerCanPreviewModerationQueue(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 0,
            'workflow_state' => 'review',
        ]);
        $account = new VisibilityTestAccount(
            id: 20,
            permissions: ['view article moderation queue'],
            roles: ['reviewer'],
            authenticated: true,
        );

        $result = $resolver->canRender($entity, $account, true);

        $this->assertTrue($result->isAllowed());
    }

    public function testArchivedPreviewDeniedWithoutAuthorization(): void
    {
        $resolver = new EditorialVisibilityResolver();
        $entity = new VisibilityTestNode([
            'nid' => 1,
            'type' => 'article',
            'uid' => 10,
            'status' => 0,
            'workflow_state' => 'archived',
        ]);
        $account = new VisibilityTestAccount(
            id: 20,
            permissions: [],
            roles: ['authenticated'],
            authenticated: true,
        );

        $result = $resolver->canRender($entity, $account, true);

        $this->assertTrue($result->isForbidden());
        $this->assertSame('Preview denied for workflow state "archived" on bundle "article".', $result->reason);
    }
}

final class VisibilityTestNode implements EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values) {}

    public function id(): int|string|null
    {
        return $this->values['nid'] ?? null;
    }

    public function uuid(): string
    {
        return '';
    }

    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }

    public function bundle(): string
    {
        return (string) ($this->values['type'] ?? '');
    }

    public function isNew(): bool
    {
        return $this->id() === null;
    }

    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { $this->values[$name] = $value; return $this; }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}

final class VisibilityTestAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int|string $id,
        private readonly array $permissions,
        private readonly array $roles,
        private readonly bool $authenticated,
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
        return $this->authenticated;
    }
}
