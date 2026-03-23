<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\EditorialWorkflowService;

#[CoversClass(EditorialWorkflowService::class)]
final class EditorialWorkflowServiceTest extends TestCase
{
    public function testTransitionDraftToReviewUpdatesStateAndAudit(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'draft',
            'status' => 0,
        ]);

        $service = new EditorialWorkflowService(
            coreBundles: ['article'],
            workflow: EditorialWorkflowPreset::create(),
            clock: static fn(): int => 1700000000,
        );
        $account = new TestAccount(99, ['submit article for review']);

        $service->transitionNode($node, 'review', $account);

        $this->assertSame('review', $node->get('workflow_state'));
        $this->assertSame(0, $node->get('status'));

        $lastTransition = $node->get('workflow_last_transition');
        $this->assertIsArray($lastTransition);
        $this->assertSame('submit_for_review', $lastTransition['id']);
        $this->assertSame('submit article for review', $lastTransition['required_permission']);

        $audit = $node->get('workflow_audit');
        $this->assertIsArray($audit);
        $this->assertCount(1, $audit);
        $this->assertSame('draft', $audit[0]['from']);
        $this->assertSame('review', $audit[0]['to']);
        $this->assertSame('submit_for_review', $audit[0]['transition']);
        $this->assertSame('99', $audit[0]['uid']);
        $this->assertSame(1700000000, $audit[0]['at']);
    }

    public function testTransitionPublishedToArchivedSetsUnpublishedStatus(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'published',
            'status' => 1,
        ]);

        $service = new EditorialWorkflowService(
            coreBundles: ['article'],
            workflow: EditorialWorkflowPreset::create(),
            clock: static fn(): int => 1700000000,
        );
        $account = new TestAccount(10, ['archive article content']);

        $service->transitionNode($node, 'archived', $account);

        $this->assertSame('archived', $node->get('workflow_state'));
        $this->assertSame(0, $node->get('status'));
    }

    public function testTransitionThrowsForIllegalEdge(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'draft',
            'status' => 0,
        ]);

        $service = new EditorialWorkflowService(coreBundles: ['article'], workflow: EditorialWorkflowPreset::create());
        $account = new TestAccount(1, ['administer nodes']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflow transition: draft -> archived.');
        $service->transitionNode($node, 'archived', $account);
    }

    public function testTransitionThrowsForMissingPermission(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'review',
            'status' => 0,
        ]);

        $service = new EditorialWorkflowService(coreBundles: ['article'], workflow: EditorialWorkflowPreset::create());
        $account = new TestAccount(1, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission denied for workflow transition review -> published');
        $service->transitionNode($node, 'published', $account);
    }

    public function testTransitionThrowsForRoleDeniedEvenWithPermission(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'published',
            'status' => 1,
        ]);

        $service = new EditorialWorkflowService(coreBundles: ['article'], workflow: EditorialWorkflowPreset::create());
        $account = new TestAccount(
            id: 1,
            permissions: ['archive article content'],
            roles: ['reviewer'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Role not authorized for transition "archive"');
        $service->transitionNode($node, 'archived', $account);
    }

    public function testTransitionMetadataForCurrentStateIsDeterministic(): void
    {
        $node = new TestFieldableNode([
            'type' => 'article',
            'workflow_state' => 'review',
            'status' => 0,
        ]);

        $service = new EditorialWorkflowService(coreBundles: ['article'], workflow: EditorialWorkflowPreset::create());
        $metadata = $service->getAvailableTransitionMetadata($node);

        $this->assertCount(2, $metadata);
        $this->assertSame('publish', $metadata[0]['id']);
        $this->assertSame('publish article content', $metadata[0]['required_permission']);
        $this->assertSame('send_back', $metadata[1]['id']);
        $this->assertSame('return article to draft', $metadata[1]['required_permission']);
    }
}

final class TestFieldableNode implements FieldableInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = []) {}

    public function hasField(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function getFieldDefinitions(): array
    {
        return [];
    }
}

final class TestAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int|string $id,
        private readonly array $permissions,
        private readonly array $roles = [],
    ) {}

    public function id(): int|string
    {
        return $this->id;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
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
