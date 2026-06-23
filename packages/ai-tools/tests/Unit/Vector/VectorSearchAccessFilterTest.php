<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Vector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\AI\Tools\Vector\VectorSearchTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

/**
 * Security: {@see VectorSearchTool} must apply the same per-entity 'view'
 * AccessPolicy gate (and field-access filter) its sibling read tools apply, so
 * a caller granted only the coarse `tool.vector.search` capability cannot
 * receive vector hits — entity ids + metadata — for entities it may not view.
 *
 * Before this fix the tool checked only `tool.vector.search`, then returned the
 * raw `EmbeddingStorageInterface::search()` rows (entity type/id + metadata, and
 * the embedding vector) with no `canViewEntity()` filter — the same disclosure
 * class as #1768, for an authenticated initiator without `view` on the matched
 * entities.
 */
#[CoversClass(VectorSearchTool::class)]
final class VectorSearchAccessFilterTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        // Two embedded entities. Entity '2' is view-forbidden.
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'visible doc']));
        $this->repo->seed(new ToolTestEntity(['id' => '2', 'title' => 'secret doc']));

        // The tool looks the backing entity up by the embedding's entityTypeId
        // ('node' here); the rows themselves report the fixture's 'tool_test'
        // type, which the policy below is bound to (mirrors the sibling tests).
        $type = new EntityType(
            id: 'node',
            label: 'Node',
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
                        ? AccessResult::forbidden('secret')
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

    /**
     * A storage double whose `search()` returns SimilarityResult-shaped rows
     * (duck-typed: `->embedding->{entityTypeId,entityId,metadata}` + `->score`),
     * one per seeded entity id. Constructed without importing ai-vector, exactly
     * as the tool consumes it.
     *
     * @param list<array{type:string,id:string,metadata:array<string,mixed>}> $rows
     */
    private function storageReturning(array $rows): object
    {
        $results = [];
        foreach ($rows as $i => $row) {
            $embedding = new \stdClass();
            $embedding->entityTypeId = $row['type'];
            $embedding->entityId = $row['id'];
            $embedding->vector = [0.1, 0.2, 0.3];
            $embedding->metadata = $row['metadata'];
            $result = new \stdClass();
            $result->embedding = $embedding;
            $result->score = 1.0 - ($i * 0.1);
            $results[] = $result;
        }

        return new class ($results) {
            /** @param list<object> $results */
            public function __construct(private readonly array $results) {}

            /** @param list<float> $vector */
            public function search(array $vector, int $limit): array
            {
                return array_slice($this->results, 0, $limit);
            }
        };
    }

    private function provider(): object
    {
        return new class {
            /** @return list<float> */
            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }
        };
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

    private function tool(object $storage): VectorSearchTool
    {
        $provider = $this->provider();

        return new VectorSearchTool(
            $this->etm,
            fn(): object => $provider,
            fn(): object => $storage,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function resultIds(array $data): array
    {
        $results = $data['results'] ?? [];

        return array_values(array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $results));
    }

    #[Test]
    public function search_drops_a_view_forbidden_result(): void
    {
        $storage = $this->storageReturning([
            ['type' => 'node', 'id' => '1', 'metadata' => ['title' => 'visible doc']],
            ['type' => 'node', 'id' => '2', 'metadata' => ['title' => 'secret doc']],
        ]);
        $tool = $this->tool($storage);
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(['query' => 'find'], $this->account(['tool.vector.search']));

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->resultIds($data);
        $this->assertContains('1', $ids, 'the viewable hit is returned');
        $this->assertNotContains('2', $ids, 'the view-forbidden hit must not be returned');
    }

    #[Test]
    public function without_a_handler_the_tool_is_capability_only(): void
    {
        // No access handler attached: behavior is unchanged (capability-only),
        // so both hits surface. Pins that the fix is a no-op for handler-less
        // consumers (entity-level access enforced elsewhere in that mode).
        $storage = $this->storageReturning([
            ['type' => 'node', 'id' => '1', 'metadata' => ['title' => 'visible doc']],
            ['type' => 'node', 'id' => '2', 'metadata' => ['title' => 'secret doc']],
        ]);
        $tool = $this->tool($storage);

        $result = $tool->execute(['query' => 'find'], $this->account(['tool.vector.search']));

        $this->assertFalse($result->isError);
        $this->assertCount(2, $result->content[0]['data']['results'] ?? []);
    }

    #[Test]
    public function enforced_without_a_handler_fails_closed(): void
    {
        // Enforcement stamped (as the production registry does) but the handler
        // failed to resolve: every hit must be dropped rather than disclosed.
        $storage = $this->storageReturning([
            ['type' => 'node', 'id' => '1', 'metadata' => ['title' => 'visible doc']],
        ]);
        $tool = $this->tool($storage);
        $tool->markAccessEnforced();

        $result = $tool->execute(['query' => 'find'], $this->account(['tool.vector.search']));

        $this->assertFalse($result->isError);
        $this->assertSame([], $result->content[0]['data']['results'] ?? null);
    }

    #[Test]
    public function metadata_is_filtered_through_field_access(): void
    {
        // A policy forbidding the 'secret' field must strip it from a returned
        // hit's metadata, mirroring applyFieldAccessFilter() on the sibling read
        // tools. A field policy must implement BOTH interfaces and is passed in
        // the single policies array (EntityAccessHandler delegates fieldAccess()
        // only to policies that also implement FieldAccessPolicyInterface).
        $fieldPolicy = new class implements AccessPolicyInterface, \Waaseyaa\Access\FieldAccessPolicyInterface {
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
                return $fieldName === 'secret' ? AccessResult::forbidden('hidden field') : AccessResult::neutral();
            }
        };
        $handler = new EntityAccessHandler([$fieldPolicy]);

        $storage = $this->storageReturning([
            ['type' => 'node', 'id' => '1', 'metadata' => ['title' => 'visible doc', 'secret' => 'leak']],
        ]);
        $tool = $this->tool($storage);
        $tool->setAccessHandler($handler);

        $result = $tool->execute(['query' => 'find'], $this->account(['tool.vector.search']));

        $this->assertFalse($result->isError);
        $metadata = $result->content[0]['data']['results'][0]['metadata'] ?? [];
        $this->assertArrayHasKey('title', $metadata);
        $this->assertArrayNotHasKey('secret', $metadata, 'the field-access-forbidden metadata key must be dropped');
    }
}
