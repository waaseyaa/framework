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
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AI\Tools\Entity\EntityCreateTool;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * #1638: the entity *write* tools must enforce per-field edit access for every
 * submitted field, exactly as the JSON:API write path does
 * (`JsonApiController::store()`/`update()` loop `checkFieldAccess('edit')` and
 * refuse on `isForbidden()`). Open-by-default — a field with no field policy
 * opinion stays writable; only an explicit Forbidden denies.
 *
 * Pre-fix, both tools bulk-applied `set()` for every key after only the coarse
 * `tool.entity.*` capability + entity-level access + identity-key guard — so an
 * agent with entity-update access could set a field a {@see FieldAccessPolicyInterface}
 * forbids (e.g. `user.roles`/`status`), re-opening the B-1 field-escalation that
 * the HTTP path closes.
 */
#[CoversClass(EntityUpdateTool::class)]
#[CoversClass(EntityCreateTool::class)]
final class EntityWriteFieldAccessTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original', 'status' => 0]));
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);
    }

    /**
     * Entity-level access is open (so the per-field guard is what we exercise);
     * the named field is Forbidden on `edit`, every other field is Neutral.
     */
    private function handler(string $forbiddenEditField): EntityAccessHandler
    {
        $policy = new class ($forbiddenEditField) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly string $forbiddenEditField) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'edit' && $fieldName === $this->forbiddenEditField
                    ? AccessResult::forbidden()
                    : AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
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
                return true;
            }
        };
    }

    #[Test]
    public function update_refuses_a_field_the_account_may_not_edit(): void
    {
        $tool = new EntityUpdateTool($this->etm);
        $tool->setAccessHandler($this->handler(forbiddenEditField: 'status'));

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New', 'status' => 1]],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->saved, 'a forbidden field must abort the whole write — nothing saved');
    }

    #[Test]
    public function update_allows_fields_with_no_field_policy_opinion(): void
    {
        $tool = new EntityUpdateTool($this->etm);
        $tool->setAccessHandler($this->handler(forbiddenEditField: 'status'));

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New']],
            $this->account(['tool.entity.update']),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $this->assertSame('New', $this->repo->saved[0]->get('title'));
    }

    #[Test]
    public function update_without_a_handler_stays_capability_only(): void
    {
        // No handler attached: per-field enforcement is off, prior behavior kept.
        $tool = new EntityUpdateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New', 'status' => 1]],
            $this->account(['tool.entity.update']),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
    }

    #[Test]
    public function create_refuses_a_field_the_account_may_not_edit(): void
    {
        $tool = new EntityCreateTool($this->etm);
        $tool->setAccessHandler($this->handler(forbiddenEditField: 'status'));

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['title' => 'Made', 'status' => 1]],
            $this->account(['tool.entity.create']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->saved, 'a forbidden field must abort the whole create — nothing saved');
    }

    #[Test]
    public function create_allows_fields_with_no_field_policy_opinion(): void
    {
        $tool = new EntityCreateTool($this->etm);
        $tool->setAccessHandler($this->handler(forbiddenEditField: 'status'));

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['title' => 'Made']],
            $this->account(['tool.entity.create']),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $this->assertSame('Made', $this->repo->saved[0]->get('title'));
    }
}
