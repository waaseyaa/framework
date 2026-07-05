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
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Security: RelationshipTraverseTool must apply the same per-entity 'view'
 * AccessPolicy gate that EntityReadTool/EntityListTool/EntitySearchTool apply
 * to the relationship EDGE record, so a caller cannot enumerate relationship
 * rows it is forbidden to view — AND it must additionally gate the ENDPOINT
 * entities (to_entity_type/to_entity_id) an edge discloses, mirroring
 * RelationshipTraversalService::filterByEndpointVisibility() (#1874's class of
 * fix, applied here at the tool layer).
 *
 * Before this fix the tool (a) queried the relationship repository with
 * imagined criteria field names (source_type/source_id/type) that do not
 * exist on the real schema (RelationshipSchemaManager: from_entity_type,
 * from_entity_id, to_entity_type, to_entity_id, relationship_type) — so it
 * always returned 0 edges, masking the leak below — and (b) even once
 * queried correctly, never checked whether the account could view the
 * endpoint entity whose identity an edge discloses.
 *
 * This tool is in the DEFAULT anonymous MCP read allowlist
 * ({@see \Waaseyaa\MCP\Auth\PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES}),
 * so an unauthenticated /mcp caller reaches it.
 */
#[CoversClass(RelationshipTraverseTool::class)]
final class RelationshipTraverseAccessFilterTest extends TestCase
{
    private RelationshipEdgeRepository $edgeRepo;
    private InMemoryToolRepository $nodeRepo;
    private EntityAccessHandler $handler;

    protected function setUp(): void
    {
        $this->edgeRepo = new RelationshipEdgeRepository();
        $this->nodeRepo = new InMemoryToolRepository();

        // A single policy bound to the fixture's reported type ('tool_test'),
        // shared by both edge entities and endpoint node entities: any id in
        // FORBIDDEN is view-forbidden, everything else is allowed.
        $policy = new class implements AccessPolicyInterface {
            /** @var list<string> */
            private const FORBIDDEN = ['2', 'secret-1'];

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'tool_test';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view') {
                    return in_array((string) $entity->id(), self::FORBIDDEN, true)
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

    private function etm(): EntityTypeManagerInterface
    {
        $relationshipType = new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $nodeType = new EntityType(
            id: 'node',
            label: 'Node',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        return new MultiTypeEntityTypeManager([
            'relationship' => [$relationshipType, $this->edgeRepo],
            'node' => [$nodeType, $this->nodeRepo],
        ]);
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
    public function execute_queries_by_the_real_schema_field_names_not_the_imagined_ones(): void
    {
        // PART 1 (prerequisite): the relationship table has no
        // source_type/source_id/type columns. Querying by those imagined
        // names silently falls through to a `_data` JSON lookup that never
        // matches, so the tool always returned 0 edges — masking the
        // endpoint-identity leak entirely, since an empty result set never
        // discloses anything. Assert the tool queries by the REAL columns.
        $tool = new RelationshipTraverseTool($this->etm());

        $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10', 'relationship_type' => 'friend'],
            $this->account(['tool.relationship.traverse']),
        );

        $criteria = $this->edgeRepo->lastCriteria;
        $this->assertSame('node', $criteria['from_entity_type'] ?? null);
        $this->assertSame('10', $criteria['from_entity_id'] ?? null);
        $this->assertSame('friend', $criteria['relationship_type'] ?? null);
        $this->assertArrayNotHasKey('source_type', $criteria, 'source_type is not a real relationship column');
        $this->assertArrayNotHasKey('source_id', $criteria, 'source_id is not a real relationship column');
        $this->assertArrayNotHasKey('type', $criteria, 'type is not a real relationship column (relationship_type is)');
    }

    #[Test]
    public function traverse_excludes_a_view_forbidden_relationship(): void
    {
        // Source must be viewable so the source gate (R8-c) passes and this
        // test exercises the EDGE-level filter it is about.
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '10', 'title' => 'viewable source']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'visible-1', 'title' => 'visible target']));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => '1', 'title' => 'visible edge',
            'from_entity_type' => 'node', 'from_entity_id' => '10',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => '2', 'title' => 'hidden edge',
            'from_entity_type' => 'node', 'from_entity_id' => '10',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
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
        // so both rows surface even with no endpoint info at all. Pins that
        // the fix is a no-op for handler-less consumers (entity-level access
        // enforced elsewhere in that mode).
        $this->edgeRepo->seed(new ToolTestEntity(['id' => '1', 'title' => 'edge one']));
        $this->edgeRepo->seed(new ToolTestEntity(['id' => '2', 'title' => 'edge two']));

        $tool = new RelationshipTraverseTool($this->etm());

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $this->assertSame(2, ($result->content[0]['data']['count'] ?? null));
    }

    #[Test]
    public function traverse_drops_an_edge_whose_endpoint_the_account_cannot_view(): void
    {
        // PART 2 (the security fix): the edge record itself is fully
        // viewable (no edge-level policy denial) — but one edge's
        // to_entity_id points at an entity the account is forbidden to view.
        // That edge (both its id and the endpoint identity it discloses) must
        // never be returned, while a sibling edge pointing at a viewable
        // endpoint is unaffected (no over-drop).
        // Source must be viewable so the source gate (R8-c) passes and this
        // test exercises the ENDPOINT-level filter it is about.
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '10', 'title' => 'viewable source']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'visible-1', 'title' => 'visible target']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'secret-1', 'title' => 'secret target']));

        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-visible', 'title' => 'edge to visible target',
            'from_entity_type' => 'node', 'from_entity_id' => '10',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-secret', 'title' => 'edge to secret target',
            'from_entity_type' => 'node', 'from_entity_id' => '10',
            'to_entity_type' => 'node', 'to_entity_id' => 'secret-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->edgeIds($data['edges'] ?? []);
        $this->assertContains('edge-visible', $ids, 'an edge whose endpoint is viewable is still returned (no over-drop)');
        $this->assertNotContains('edge-secret', $ids, 'an edge pointing at a non-viewable endpoint must be dropped entirely');
        $this->assertSame(1, $data['count']);

        foreach ($data['edges'] ?? [] as $edge) {
            $this->assertNotSame(
                'secret-1',
                $edge['values']['to_entity_id'] ?? null,
                'the forbidden endpoint id must never be disclosed, in any surviving edge',
            );
        }
    }

    #[Test]
    public function traverse_returns_empty_when_the_source_entity_is_view_forbidden(): void
    {
        // R8-c (MCP surface): the SOURCE entity is the caller's own query
        // INPUT — supplying its id does NOT imply the caller may view it
        // (confused-deputy / existence oracle). The source node '2' is
        // view-forbidden, yet it has a published edge to a fully-VIEWABLE
        // target. Pre-fix the tool returned that edge — echoing the restricted
        // source id ('2', in from_entity_id) plus the relationship — confirming
        // the restricted entity exists and has that relationship. The gate must
        // return an EMPTY result, indistinguishable from "source has no
        // relationships" / "source absent".
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '2', 'title' => 'restricted source']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'visible-1', 'title' => 'visible target']));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-from-secret-source', 'title' => 'published edge from a restricted source',
            'from_entity_type' => 'node', 'from_entity_id' => '2',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '2'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $this->assertSame([], $data['edges'] ?? null, 'a view-forbidden source must disclose no edges (no existence oracle)');
        $this->assertSame(0, $data['count'] ?? null);
    }

    #[Test]
    public function forbidden_source_is_indistinguishable_from_an_absent_source(): void
    {
        // The forbidden-source result MUST be byte-identical to a source with
        // no relationships at all — an attacker cannot tell "restricted" apart
        // from "genuinely empty / absent".
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '2', 'title' => 'restricted source']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'visible-1', 'title' => 'visible target']));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-from-secret-source', 'title' => 'published edge from a restricted source',
            'from_entity_type' => 'node', 'from_entity_id' => '2',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
        $tool->setAccessHandler($this->handler);

        $forbidden = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '2'],
            $this->account(['tool.relationship.traverse']),
        );

        // A genuinely absent source: fresh, entirely EMPTY fixtures (the edge
        // repo double returns every seeded row regardless of criteria, so a
        // truly-empty result needs an empty edge store rather than a
        // non-matching id against the shared repo).
        $emptyEdgeRepo = new RelationshipEdgeRepository();
        $emptyNodeRepo = new InMemoryToolRepository();
        $emptyNodeRepo->seed(new ToolTestEntity(['id' => 'absent-but-viewable', 'title' => 'viewable, no edges']));
        $relationshipType = new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $nodeType = new EntityType(
            id: 'node',
            label: 'Node',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $absentTool = new RelationshipTraverseTool(new MultiTypeEntityTypeManager([
            'relationship' => [$relationshipType, $emptyEdgeRepo],
            'node' => [$nodeType, $emptyNodeRepo],
        ]));
        $absentTool->setAccessHandler($this->handler);
        $absent = $absentTool->execute(
            ['source_entity_type' => 'node', 'source_id' => 'absent-but-viewable'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($forbidden->isError);
        $this->assertFalse($absent->isError);
        $this->assertSame(
            $absent->content[0]['data'] ?? null,
            $forbidden->content[0]['data'] ?? null,
            'restricted-source and empty/absent-source results must be indistinguishable',
        );
    }

    #[Test]
    public function traverse_returns_the_edge_when_the_source_entity_is_viewable(): void
    {
        // Positive control: a viewable source with the SAME edge shape still
        // returns the edge — the source gate must not over-block.
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '1', 'title' => 'viewable source']));
        $this->nodeRepo->seed(new ToolTestEntity(['id' => 'visible-1', 'title' => 'visible target']));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-from-visible-source', 'title' => 'published edge from a viewable source',
            'from_entity_type' => 'node', 'from_entity_id' => '1',
            'to_entity_type' => 'node', 'to_entity_id' => 'visible-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '1'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $ids = $this->edgeIds($data['edges'] ?? []);
        $this->assertContains('edge-from-visible-source', $ids, 'a viewable source must still surface its edges (no over-block)');
        $this->assertSame(1, $data['count'] ?? null);
    }

    #[Test]
    public function traverse_fails_closed_when_the_endpoint_entity_cannot_be_loaded(): void
    {
        // The edge references an endpoint type the ETM has no definition for
        // (e.g. a dangling/foreign reference). Under enforcement this must be
        // dropped, not disclosed: the tool cannot prove the endpoint is
        // viewable, so "cannot check" must read identically to "forbidden".
        // Source is seeded viewable so the source gate (R8-c) passes and this
        // test exercises the ENDPOINT fail-closed path it is about.
        $this->nodeRepo->seed(new ToolTestEntity(['id' => '10', 'title' => 'viewable source']));
        $this->edgeRepo->seed(new ToolTestEntity([
            'id' => 'edge-dangling', 'title' => 'edge to unresolvable type',
            'from_entity_type' => 'node', 'from_entity_id' => '10',
            'to_entity_type' => 'ghost_type', 'to_entity_id' => 'ghost-1',
        ]));

        $tool = new RelationshipTraverseTool($this->etm());
        $tool->setAccessHandler($this->handler);

        $result = $tool->execute(
            ['source_entity_type' => 'node', 'source_id' => '10'],
            $this->account(['tool.relationship.traverse']),
        );

        $this->assertFalse($result->isError);
        $data = $result->content[0]['data'] ?? [];
        $this->assertSame([], $data['edges'] ?? null);
        $this->assertSame(0, $data['count'] ?? null);
    }
}

/**
 * Records the criteria passed to findBy() (so the field-name fix can be
 * asserted directly) while behaving like {@see InMemoryToolRepository}:
 * seeded rows are returned in full regardless of criteria content (this
 * fixture does not implement real filtering — the tool under test is
 * responsible for choosing the right criteria; a real SqlEntityStorage-backed
 * repository is what actually filters by them in production).
 */
final class RelationshipEdgeRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $store = [];

    /** @var array<string, mixed> */
    public array $lastCriteria = [];

    public function seed(EntityInterface $entity): void
    {
        $this->store[(string) $entity->id()] = $entity;
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->store[$id] ?? null;
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        $this->lastCriteria = $criteria;

        return array_values($this->store);
    }

    public function exists(string $id): bool
    {
        return isset($this->store[$id]);
    }

    public function count(array $criteria = []): int
    {
        return count($this->store);
    }

    // Unused by RelationshipTraverseTool.

    public function create(array $values = []): EntityInterface
    {
        throw new \LogicException('RelationshipEdgeRepository does not support create().');
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        throw new \LogicException('RelationshipEdgeRepository does not support save().');
    }

    public function delete(EntityInterface $entity): void
    {
        throw new \LogicException('RelationshipEdgeRepository does not support delete().');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('RelationshipEdgeRepository does not support setCurrentRevision().');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \LogicException('RelationshipEdgeRepository does not support rollback().');
    }

    public function listRevisions(string $entityId): array
    {
        return [];
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return [];
    }

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        throw new \LogicException('getQuery() not implemented in this test double');
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        return null;
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        return null;
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('RelationshipEdgeRepository does not support setPublishedRevision().');
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return [];
    }

    public function deleteMany(array $entities): int
    {
        return 0;
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }
}

/**
 * A tiny EntityTypeManager exposing several entity type + repository pairs,
 * for exercising a tool that must resolve endpoint entities of a DIFFERENT
 * type than the one it primarily queries (relationship edges vs. the node
 * entities they reference).
 */
final class MultiTypeEntityTypeManager implements EntityTypeManagerInterface
{
    /** @param array<string, array{0: EntityTypeInterface, 1: EntityRepositoryInterface}> $map */
    public function __construct(private readonly array $map) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return $this->map[$entityTypeId][0] ?? throw new \InvalidArgumentException('Unknown entity type: ' . $entityTypeId);
    }

    public function getDefinitions(): array
    {
        return array_map(static fn(array $pair): EntityTypeInterface => $pair[0], $this->map);
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->map[$entityTypeId]);
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $this->map[$entityTypeId][1] ?? throw new \InvalidArgumentException('Unknown entity type: ' . $entityTypeId);
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return [];
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new \BadMethodCallException('getStorage is not used by the entity tools.');
    }
}
