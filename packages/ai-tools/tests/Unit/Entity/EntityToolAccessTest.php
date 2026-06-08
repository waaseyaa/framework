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
use Waaseyaa\AI\Tools\Entity\EntityCreateTool;
use Waaseyaa\AI\Tools\Entity\EntityDeleteTool;
use Waaseyaa\AI\Tools\Entity\EntityListRevisionsTool;
use Waaseyaa\AI\Tools\Entity\EntityRollbackTool;
use Waaseyaa\AI\Tools\Entity\EntitySetCurrentRevisionTool;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * alpha.193: the entity tools gain optional per-entity AccessPolicy gating
 * (in addition to the coarse capability), an optional revision_log argument,
 * and new revision tools (set-current / rollback / list). This suite covers
 * the policy gate, the revision_log, and the revision tools using an in-memory
 * repository.
 */
#[CoversClass(EntityUpdateTool::class)]
#[CoversClass(EntityCreateTool::class)]
#[CoversClass(EntityDeleteTool::class)]
#[CoversClass(EntitySetCurrentRevisionTool::class)]
#[CoversClass(EntityRollbackTool::class)]
#[CoversClass(EntityListRevisionsTool::class)]
final class EntityToolAccessTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original']));
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);

        // Policy: anyone may view; writing requires the 'may write' permission.
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return AccessResult::allowed();
                }

                return $account->hasPermission('may write') ? AccessResult::allowed() : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return $account->hasPermission('may write') ? AccessResult::allowed() : AccessResult::neutral();
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

    #[Test]
    public function update_is_forbidden_when_the_policy_denies_even_with_the_capability(): void
    {
        $tool = new EntityUpdateTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New']],
            $this->account(['tool.entity.update']), // capability, but no 'may write'
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->saved, 'nothing was written');
    }

    #[Test]
    public function update_succeeds_with_the_policy_permission_and_applies_the_revision_log(): void
    {
        $tool = new EntityUpdateTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New'], 'revision_log' => 'Agent edit'],
            $this->account(['tool.entity.update', 'may write']),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $saved = $this->repo->saved[0];
        $this->assertInstanceOf(ToolTestEntity::class, $saved);
        $this->assertSame('New', $saved->get('title'));
        $this->assertSame('Agent edit', $saved->getRevisionLog());
    }

    #[Test]
    public function update_without_a_handler_is_capability_only(): void
    {
        $tool = new EntityUpdateTool($this->etm); // no access handler attached

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New']],
            $this->account(['tool.entity.update']), // capability only, no 'may write'
        );

        $this->assertFalse($result->isError, 'policy is not consulted without a handler');
        $this->assertCount(1, $this->repo->saved);
    }

    #[Test]
    public function update_without_the_capability_is_forbidden(): void
    {
        $tool = new EntityUpdateTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New']],
            $this->account([]), // no capability at all
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function create_is_policy_gated(): void
    {
        $tool = new EntityCreateTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $denied = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['id' => '2', 'title' => 'Made']],
            $this->account(['tool.entity.create']),
        );
        $this->assertTrue($denied->isError);
        $this->assertSame('forbidden', $denied->summary);

        $ok = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['id' => '2', 'title' => 'Made'], 'revision_log' => 'created by agent'],
            $this->account(['tool.entity.create', 'may write']),
        );
        $this->assertFalse($ok->isError);
        $this->assertCount(1, $this->repo->saved);
        $this->assertSame('created by agent', $this->repo->saved[0]->getRevisionLog());
    }

    #[Test]
    public function delete_is_policy_gated(): void
    {
        $this->repo->seed(new ToolTestEntity(['id' => '2', 'title' => 'Doomed']));
        $tool = new EntityDeleteTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $denied = $tool->execute(['entity_type' => 'tool_test', 'id' => '2'], $this->account(['tool.entity.delete']));
        $this->assertTrue($denied->isError);
        $this->assertSame([], $this->repo->deleted);

        $ok = $tool->execute(['entity_type' => 'tool_test', 'id' => '2'], $this->account(['tool.entity.delete', 'may write']));
        $this->assertFalse($ok->isError);
        $this->assertSame(['2'], $this->repo->deleted);
    }

    #[Test]
    public function set_current_revision_tool_calls_the_repository(): void
    {
        $tool = new EntitySetCurrentRevisionTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $denied = $tool->execute(['entity_type' => 'tool_test', 'id' => '1', 'revision_id' => 2], $this->account(['tool.entity.update']));
        $this->assertTrue($denied->isError, 'set-current honors the update policy');
        $this->assertSame([], $this->repo->setCurrentCalls);

        $ok = $tool->execute(['entity_type' => 'tool_test', 'id' => '1', 'revision_id' => 2], $this->account(['tool.entity.update', 'may write']));
        $this->assertFalse($ok->isError);
        $this->assertSame([['1', 2]], $this->repo->setCurrentCalls);
    }

    #[Test]
    public function rollback_tool_calls_the_repository(): void
    {
        $tool = new EntityRollbackTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $ok = $tool->execute(['entity_type' => 'tool_test', 'id' => '1', 'target_revision_id' => 1], $this->account(['tool.entity.update', 'may write']));
        $this->assertFalse($ok->isError);
        $this->assertSame([['1', 1]], $this->repo->rollbackCalls);
    }

    #[Test]
    public function list_revisions_tool_returns_history(): void
    {
        $this->repo->revisions = [
            new ToolTestEntity(['id' => '1', 'revision_id' => 2, 'title' => 'v2', 'is_current' => true]),
            new ToolTestEntity(['id' => '1', 'revision_id' => 1, 'title' => 'v1']),
        ];
        $tool = new EntityListRevisionsTool($this->etm);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '1'], $this->account(['tool.entity.read']));
        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $this->assertCount(2, $data['revisions']);
        $this->assertSame(2, $data['revisions'][0]['revision_id']);
        $this->assertTrue($data['revisions'][0]['is_current']);
    }
}
