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
use Waaseyaa\AI\Tools\Entity\EntityListRevisionsTool;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * R8-c (audit R8 WP2, MCP read tier): entity.read and entity.list_revisions
 * both carry `tool.entity.read`, which is on
 * {@see \Waaseyaa\MCP\Auth\PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES} and
 * is non-destructive — so both are anonymous-reachable. Before this fix a
 * VIEW-FORBIDDEN existing entity returned a textually distinguishable error
 * ("Account X is not permitted to view type/id", summary 'forbidden') while a
 * truly-ABSENT id returned "type/id not found" — an existence oracle: an
 * anonymous caller enumerating ids could tell "exists but forbidden" from
 * "absent". This pins that the forbidden and absent outcomes are now
 * byte-indistinguishable on the wire (same content, same isError), for the
 * SAME id, while a viewable entity still returns its data.
 */
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntityListRevisionsTool::class)]
final class EntityReadOracleClosureTest extends TestCase
{
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        // Forbids 'view' on id '1'; everything else is viewable.
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return (string) $entity->id() === '1'
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

    private function type(): EntityType
    {
        return new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
    }

    /** @param list<EntityInterface> $seed */
    private function etm(array $seed): SingleTypeEntityTypeManager
    {
        $repo = new InMemoryToolRepository();
        foreach ($seed as $entity) {
            $repo->seed($entity);
        }

        return new SingleTypeEntityTypeManager($this->type(), $repo);
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
                return false;
            }
        };
    }

    #[Test]
    public function read_forbidden_is_indistinguishable_from_absent(): void
    {
        // Scenario A: entity '1' exists but 'view' is forbidden.
        $forbiddenTool = new EntityReadTool($this->etm([
            new ToolTestEntity(['id' => '1', 'title' => 'classified secret']),
        ]));
        $forbiddenTool->setAccessHandler($this->handler);

        // Scenario B: id '1' is genuinely absent (empty repo). Same id string,
        // so a post-fix "not found" body is byte-identical to scenario A's.
        $absentTool = new EntityReadTool($this->etm([]));
        $absentTool->setAccessHandler($this->handler);

        $account = $this->account(['tool.entity.read']);
        $forbidden = $forbiddenTool->execute(['entity_type' => 'tool_test', 'id' => '1'], $account);
        $absent = $absentTool->execute(['entity_type' => 'tool_test', 'id' => '1'], $account);

        $this->assertTrue($forbidden->isError);
        $this->assertTrue($absent->isError);
        $this->assertSame($absent->content, $forbidden->content, 'forbidden read must be byte-indistinguishable from absent');
        $this->assertSame($absent->summary, $forbidden->summary, 'forbidden and absent summaries must match too');
    }

    #[Test]
    public function read_returns_data_for_a_viewable_entity(): void
    {
        $tool = new EntityReadTool($this->etm([
            new ToolTestEntity(['id' => '2', 'title' => 'public']),
        ]));
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '2'], $this->account(['tool.entity.read']));

        $this->assertFalse($result->isError, 'a viewable entity must still be readable (no over-block)');
        $this->assertSame('2', (string) ($result->content[0]['data']['id'] ?? null));
    }

    #[Test]
    public function list_revisions_forbidden_is_indistinguishable_from_absent(): void
    {
        $forbiddenTool = new EntityListRevisionsTool($this->etm([
            new ToolTestEntity(['id' => '1', 'title' => 'classified secret', 'revision_id' => 5]),
        ]));
        $forbiddenTool->setAccessHandler($this->handler);

        $absentTool = new EntityListRevisionsTool($this->etm([]));
        $absentTool->setAccessHandler($this->handler);

        $account = $this->account(['tool.entity.read']);
        $forbidden = $forbiddenTool->execute(['entity_type' => 'tool_test', 'id' => '1'], $account);
        $absent = $absentTool->execute(['entity_type' => 'tool_test', 'id' => '1'], $account);

        $this->assertTrue($forbidden->isError);
        $this->assertTrue($absent->isError);
        $this->assertSame($absent->content, $forbidden->content, 'forbidden list_revisions must be byte-indistinguishable from absent');
        $this->assertSame($absent->summary, $forbidden->summary);
    }

    #[Test]
    public function list_revisions_returns_history_for_a_viewable_entity(): void
    {
        $entity = new ToolTestEntity(['id' => '2', 'title' => 'public', 'revision_id' => 9, 'is_current' => true]);
        $etm = $this->etm([$entity]);
        // The in-memory repository returns its seeded entity as the single
        // revision from listRevisions() via the fixture's revisions bag.
        $repo = $etm->getRepository('tool_test');
        if ($repo instanceof InMemoryToolRepository) {
            $repo->revisions = [$entity];
        }

        $tool = new EntityListRevisionsTool($etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '2'], $this->account(['tool.entity.read']));

        $this->assertFalse($result->isError, 'a viewable entity must still list revisions (no over-block)');
        $this->assertSame('2', (string) ($result->content[0]['data']['id'] ?? null));
    }
}
