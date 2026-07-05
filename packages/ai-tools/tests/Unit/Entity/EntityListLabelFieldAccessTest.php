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
use Waaseyaa\AI\Tools\Entity\EntityListRevisionsTool;
use Waaseyaa\AI\Tools\Entity\EntityListTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * R7 WP1 (ai-tools fold): the entity label/title field-access channel on the
 * enumeration tools.
 *
 * `EntityInterface::label()` reads the label-key field directly and bypasses
 * {@see FieldAccessPolicyInterface}, so `EntityListRevisionsTool` /
 * `EntityListTool` emitting a bare `label()` per row leaked a field-access-
 * restricted label even after the entity-level `view` gate passed.
 *
 * `EntityListRevisionsTool` is the LIVE case: its `tool.entity.read` capability
 * is on the public MCP anonymous read tier
 * (`PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES`), so an anonymous MCP client
 * could call `entity.list_revisions` and receive labels a policy forbids.
 * `EntityListTool` (`tool.entity.list`) is NOT on the anonymous tier — fixed
 * here for consistency / defense in depth.
 */
#[CoversClass(EntityListRevisionsTool::class)]
#[CoversClass(EntityListTool::class)]
final class EntityListLabelFieldAccessTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        // Parent entity is viewable at the entity level; its label-key field
        // ('title') is what the policy forbids.
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'classified title', 'revision_id' => 5]));
        // Revision history the tool enumerates — each carries the same
        // restricted label.
        $this->repo->revisions = [
            new ToolTestEntity(['id' => '1', 'title' => 'classified title', 'revision_id' => 5, 'is_current' => true]),
        ];

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
     * Entity-level 'view' is Allowed (the tool proceeds), but the label-key
     * field 'title' is field-access-Forbidden — the residual R7 WP1 closes on
     * the enumeration tools.
     */
    private function forbidLabelFieldHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' ? AccessResult::allowed() : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $fieldName === 'title' && $operation === 'view'
                    ? AccessResult::forbidden('classified label')
                    : AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    private function allowLabelHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' ? AccessResult::allowed() : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    /** @param list<string> $permissions */
    private function account(array $permissions): AccountInterface
    {
        return new class($permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string
            {
                return 0;
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
    public function list_revisions_omits_the_label_when_the_label_field_is_forbidden(): void
    {
        $tool = new EntityListRevisionsTool($this->etm);
        $tool->setAccessHandler($this->forbidLabelFieldHandler());

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1'],
            $this->account(['tool.entity.read']),
        );

        self::assertFalse($result->isError, 'entity-level view is Allowed, so the tool still succeeds');
        $revisions = $result->content[0]['data']['revisions'] ?? [];
        self::assertNotSame([], $revisions);
        foreach ($revisions as $row) {
            self::assertArrayNotHasKey('label', $row, 'a field-access-forbidden label must be omitted, not emitted');
        }
        self::assertStringNotContainsString('classified title', json_encode($result->content, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function list_revisions_shows_the_label_when_it_is_not_restricted(): void
    {
        $tool = new EntityListRevisionsTool($this->etm);
        $tool->setAccessHandler($this->allowLabelHandler());

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1'],
            $this->account(['tool.entity.read']),
        );

        self::assertFalse($result->isError);
        $revisions = $result->content[0]['data']['revisions'] ?? [];
        self::assertSame('classified title', $revisions[0]['label'] ?? null);
    }

    #[Test]
    public function list_revisions_is_capability_only_without_a_handler(): void
    {
        // No handler attached: prior behavior preserved (label emitted). Pins
        // the fix as a no-op for handler-less consumers so existing tool tests
        // do not break.
        $tool = new EntityListRevisionsTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1'],
            $this->account(['tool.entity.read']),
        );

        self::assertFalse($result->isError);
        $revisions = $result->content[0]['data']['revisions'] ?? [];
        self::assertSame('classified title', $revisions[0]['label'] ?? null);
    }

    #[Test]
    public function list_omits_the_label_when_the_label_field_is_forbidden(): void
    {
        // Defense-in-depth companion (not anonymous-reachable, but the same
        // channel is closed on EntityListTool too).
        $tool = new EntityListTool($this->etm);
        $tool->setAccessHandler($this->forbidLabelFieldHandler());

        $result = $tool->execute(
            ['entity_type' => 'tool_test'],
            $this->account(['tool.entity.list']),
        );

        self::assertFalse($result->isError);
        $items = $result->content[0]['data']['items'] ?? [];
        self::assertNotSame([], $items);
        foreach ($items as $item) {
            self::assertArrayNotHasKey('label', $item, 'a field-access-forbidden label must be omitted from the list output');
        }
        self::assertStringNotContainsString('classified title', json_encode($result->content, JSON_THROW_ON_ERROR));
    }
}
