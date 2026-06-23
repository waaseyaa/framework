<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Relationship;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Relationship\RelationshipTraverseTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * Security: RelationshipTraverseTool must apply the same per-entity 'view'
 * AccessPolicy gate that EntityReadTool/EntityListTool/EntitySearchTool apply,
 * so a caller cannot enumerate relationship rows it is forbidden to view.
 *
 * This tool is in the DEFAULT anonymous MCP read allowlist
 * ({@see \Waaseyaa\MCP\Auth\PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES}),
 * so an unauthenticated /mcp caller reaches it. Before this fix it ran
 * `findBy()` and emitted every matching row's values with no view check —
 * leaking unpublished/forbidden relationships (which the access model denies)
 * to anyone holding the coarse `tool.relationship.traverse` capability.
 */
#[CoversClass(RelationshipTraverseTool::class)]
final class RelationshipTraverseAccessFilterTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        // Two relationship rows from the same source. Row '2' is view-forbidden
        // (e.g. an unpublished relationship an anon caller may not see).
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'visible edge', 'source_id' => '10']));
        $this->repo->seed(new ToolTestEntity(['id' => '2', 'title' => 'hidden edge', 'source_id' => '10']));

        // The EntityTypeManager must answer to the 'relationship' type id the
        // tool looks up; the rows themselves report the fixture's 'tool_test'
        // type, which the policy below is bound to.
        $type = new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);

        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return (string) $entity->id() === '2'
                        ? AccessResult::forbidden('hidden')
                        : AccessResult::allowed();
                }

                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
        $this->handler = new EntityAccessHandler([$policy]);
    }

    /** @param list<string> $permissions */
    private function account(array $permissions): AccountInterface
    {
        return new class ($permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @return list<string>
     */
    private function edgeIds(array $edges): array
    {
        return array_values(array_map(static fn(array $e): string => (string) $e['id'], $edges));
    }

    #[Test]
    public function traverse_excludes_a_view_forbidden_relationship(): void
    {
        $tool = new RelationshipTraverseTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->edgeIds($data['edges'] ?? []);
        $this->assertContains('1', $ids, 'the viewable relationship is returned');
        $this->assertNotContains('2', $ids, 'the view-forbidden relationship must not be returned');
        $this->assertSame(1, $data['count'], 'count reflects the post-filter set');
    }

    #[Test]
    public function without_a_handler_the_tool_is_capability_only(): void
    {
        // No access handler attached: behavior is unchanged (capability-only),
        // so both rows surface. Pins that the fix is a no-op for handler-less
        // consumers (entity-level access enforced elsewhere in that mode).
        $tool = new RelationshipTraverseTool($this->etm);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $this->assertSame(2, ($result->content[0]['data']['count'] ?? null));
    }
}
