<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\Tools\EditorialTools;
use Waaseyaa\Mcp\Tools\McpTool;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowStateMachine;

#[CoversClass(EditorialTools::class)]
#[CoversClass(McpTool::class)]
final class EditorialToolsTest extends TestCase
{
    #[Test]
    public function transitionRequiresToState(): void
    {
        $tools = $this->createEditorialTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "to_state"');

        $tools->transition(['type' => 'node', 'id' => '1', 'to_state' => '']);
    }

    #[Test]
    public function transitionRejectsUnknownState(): void
    {
        $tools = $this->createEditorialTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown editorial workflow state');

        $tools->transition(['type' => 'node', 'id' => '1', 'to_state' => 'nonexistent']);
    }

    #[Test]
    public function validateRequiresType(): void
    {
        $tools = $this->createEditorialTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "type"');

        $tools->validate(['type' => '', 'id' => '1']);
    }

    #[Test]
    public function validateRejectsNonNodeType(): void
    {
        $tools = $this->createEditorialTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only support "node"');

        $tools->validate(['type' => 'user', 'id' => '1']);
    }

    #[Test]
    public function validateRequiresId(): void
    {
        $tools = $this->createEditorialTools(hasDefinition: true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "id"');

        $tools->validate(['type' => 'node', 'id' => '']);
    }

    #[Test]
    public function publishSetsPublishedState(): void
    {
        $tools = $this->createEditorialTools();

        // Will throw because entity type not registered, but validates the state is set
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown entity type');

        $tools->publish(['type' => 'node', 'id' => '1']);
    }

    #[Test]
    public function archiveSetsArchivedState(): void
    {
        $tools = $this->createEditorialTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown entity type');

        $tools->archive(['type' => 'node', 'id' => '1']);
    }

    private function createEditorialTools(bool $hasDefinition = false): EditorialTools
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn($hasDefinition);

        $stateMachine = new EditorialWorkflowStateMachine();

        return new EditorialTools(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            accessHandler: new EntityAccessHandler([]),
            account: $this->anonymousAccount(),
            editorialStateMachine: $stateMachine,
            editorialTransitionResolver: new EditorialTransitionAccessResolver($stateMachine),
        );
    }

    private function anonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string { return 0; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['anonymous']; }
            public function isAuthenticated(): bool { return false; }
        };
    }
}
