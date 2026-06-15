<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Entity\EntityListTool;
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * C-13 (security): EntityListTool / EntitySearchTool must apply the same
 * per-entity 'view' AccessPolicy gate that EntityReadTool already applies, so
 * an agent cannot enumerate (list) or substring-match (search) entities it is
 * forbidden to view. The filter must run against the REAL initiating account
 * threaded into execute() — not a bypass/system account.
 */
#[CoversClass(EntityListTool::class)]
#[CoversClass(EntitySearchTool::class)]
final class EntityListSearchAccessFilterTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        // Two entities sharing a substring ('secret') in their title so search
        // would match both absent any access filtering.
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'public secret']));
        $this->repo->seed(new ToolTestEntity(['id' => '2', 'title' => 'classified secret']));

        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);

        // Policy: id '2' is view-forbidden; everything else is viewable.
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return (string) $entity->id() === '2'
                        ? AccessResult::forbidden('classified')
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
        return new class($permissions) implements AccountInterface {
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
                return true;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return list<string>
     */
    private function ids(array $items): array
    {
        return array_values(array_map(static fn(array $i): string => (string) $i['id'], $items));
    }

    #[Test]
    public function list_excludes_a_view_forbidden_entity(): void
    {
        $tool = new EntityListTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['entity_type' => 'tool_test'],
            $this->account(['tool.entity.list']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->ids($data['items'] ?? []);
        $this->assertContains('1', $ids, 'the viewable entity is listed');
        $this->assertNotContains('2', $ids, 'the view-forbidden entity must not be listed');
        $this->assertSame(1, $data['count'], 'count reflects the post-filter set');
    }

    #[Test]
    public function search_excludes_a_view_forbidden_entity(): void
    {
        $tool = new EntitySearchTool($this->etm);
        $tool->setAccessHandler($this->handler);

        // Both titles contain 'secret'; only the viewable one may surface.
        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'query' => 'secret'],
            $this->account(['tool.entity.search']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->ids($data['items'] ?? []);
        $this->assertContains('1', $ids, 'the viewable match is returned');
        $this->assertNotContains('2', $ids, 'the view-forbidden match must not be returned');
        $this->assertSame(1, $data['count'], 'count reflects the post-filter set');
    }

    #[Test]
    public function without_a_handler_both_tools_are_capability_only(): void
    {
        // No access handler attached: behavior is unchanged (capability-only),
        // so both entities surface. This pins that the fix is a no-op for
        // handler-less consumers.
        $list = new EntityListTool($this->etm);
        $listResult = $list->execute(['entity_type' => 'tool_test'], $this->account(['tool.entity.list']));
        $this->assertFalse($listResult->isError);
        $this->assertSame(2, ($listResult->content[0]['data']['count'] ?? null));

        $search = new EntitySearchTool($this->etm);
        $searchResult = $search->execute(
            ['entity_type' => 'tool_test', 'query' => 'secret'],
            $this->account(['tool.entity.search']),
        );
        $this->assertFalse($searchResult->isError);
        $this->assertSame(2, ($searchResult->content[0]['data']['count'] ?? null));
    }
}
