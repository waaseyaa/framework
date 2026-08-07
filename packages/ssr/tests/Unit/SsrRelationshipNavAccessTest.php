<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * Audit m1 / R6 PR2 exploit-closed regression for the relationship-navigation
 * side context of the SSR render path.
 *
 * {@see SsrPageHandler::buildRelationshipRenderContext()} surfaces the
 * type/id/path/label of every entity RELATED to the page being rendered, gated
 * only by {@see \Waaseyaa\Workflows\WorkflowVisibilityFilter} — which understands
 * PUBLISH STATUS only, not the per-entity `AccessPolicy`. So a PUBLIC node A
 * related to a PUBLISHED-BUT-VIEW-FORBIDDEN node B still disclosed B's identity
 * in the `relationship_navigation` context of node A's page, even though B's own
 * access policy denies anonymous 'view' — the same disclosure class the
 * primary-entity gate closes, on the same rendered page. The fix re-checks each
 * disclosed endpoint against the SAME per-entity view AccessPolicy and drops the
 * whole edge (fail-closed) when the endpoint is not viewable.
 *
 * The stubs below mirror the traversal stack the production code drives: a REAL
 * SQLite `relationship` table (queried by {@see \Waaseyaa\Relationship\RelationshipTraversalService})
 * plus stub node/relationship storages behind the real {@see EntityTypeManager}
 * repository factory.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrRelationshipNavAccessTest extends TestCase
{
    /** Node id whose entity-level 'view' the access policy forbids for anonymous. */
    private const string FORBIDDEN_NODE_ID = '2';
    private const string VIEWABLE_NODE_ID = '3';

    private DBALDatabase $db;
    private EntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->createRelationshipTable($this->db);
        // Node A (1) --references--> node B (2, view-forbidden) and node C (3, viewable).
        $this->insertRelationship($this->db, 1, 'node', '1', 'node', self::FORBIDDEN_NODE_ID);
        $this->insertRelationship($this->db, 2, 'node', '1', 'node', self::VIEWABLE_NODE_ID);

        $storages = [
            'node' => new RelNavNodeStorage([
                '1' => new RelNavStubNode('1', 'Source Node A'),
                self::FORBIDDEN_NODE_ID => new RelNavStubNode(self::FORBIDDEN_NODE_ID, 'Held Node B'),
                self::VIEWABLE_NODE_ID => new RelNavStubNode(self::VIEWABLE_NODE_ID, 'Public Node C'),
            ]),
            'relationship' => new RelNavRelationshipStorage([
                1 => $this->edge(1, '1', self::FORBIDDEN_NODE_ID),
                2 => $this->edge(2, '1', self::VIEWABLE_NODE_ID),
            ]),
        ];

        $this->etm = new EntityTypeManager(
            new EventDispatcher(),
            storageFactory: static fn(EntityTypeInterface $definition): EntityStorageInterface => $storages[$definition->id()],
            repositoryFactory: static fn(string $entityTypeId): StorageBackedStubRepository => new StorageBackedStubRepository($storages[$entityTypeId]),
        );
        $this->etm->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: RelNavStubNode::class,
            keys: ['id' => 'nid', 'label' => 'title'],
            group: 'content',
        ));
        $this->etm->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            group: 'content',
        ));
    }

    private function edge(int $rid, string $fromId, string $toId): Relationship
    {
        return new Relationship([
            'rid' => $rid,
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => $fromId,
            'to_entity_type' => 'node',
            'to_entity_id' => $toId,
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }

    /**
     * Allows anonymous 'view' of any published node EXCEPT the forbidden one —
     * mirrors a classification-hold / insufficient-clearance policy scoped to B.
     */
    private function forbidNodeBHandler(): EntityAccessHandler
    {
        $forbiddenId = self::FORBIDDEN_NODE_ID;

        return new EntityAccessHandler([
            new class ($forbiddenId) implements AccessPolicyInterface {
                public function __construct(private readonly string $forbiddenId) {}

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    if ($operation !== 'view') {
                        return AccessResult::neutral();
                    }

                    return (string) $entity->id() === $this->forbiddenId
                        ? AccessResult::forbidden('Node B is under legal hold.')
                        : AccessResult::allowed('Published node is viewable.');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'node';
                }
            },
        ]);
    }

    private function handler(?EntityAccessHandler $accessHandler): SsrPageHandler
    {
        return new SsrPageHandler(
            entityTypeManager: $this->etm,
            database: $this->db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($this->etm, $this->db),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: $accessHandler,
        );
    }

    private function anon(): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    /**
     * @return list<string> the related_entity_id values disclosed by the node
     *   relationship-navigation context (outbound + inbound edges).
     */
    private function disclosedRelatedIds(array $context): array
    {
        $browse = $context['relationship_navigation']['entity'] ?? [];
        $ids = [];
        foreach (['outbound', 'inbound'] as $direction) {
            foreach (is_array($browse[$direction] ?? null) ? $browse[$direction] : [] as $edge) {
                $ids[] = (string) ($edge['related_entity_id'] ?? '');
            }
        }

        return $ids;
    }

    #[Test]
    public function a_view_forbidden_related_node_is_dropped_from_the_navigation_context(): void
    {
        $handler = $this->handler($this->forbidNodeBHandler());

        $context = $handler->buildRelationshipRenderContext(new RelNavStubNode('1', 'Source Node A'), $this->anon());
        $relatedIds = $this->disclosedRelatedIds($context);

        self::assertNotContains(
            self::FORBIDDEN_NODE_ID,
            $relatedIds,
            'a published-but-view-forbidden related node must NOT leak its identity through relationship_navigation',
        );
    }

    #[Test]
    public function a_viewable_related_node_still_appears_in_the_navigation_context(): void
    {
        // Positive control: the fix must not over-drop a genuinely viewable
        // related node.
        $handler = $this->handler($this->forbidNodeBHandler());

        $context = $handler->buildRelationshipRenderContext(new RelNavStubNode('1', 'Source Node A'), $this->anon());
        $relatedIds = $this->disclosedRelatedIds($context);

        self::assertContains(
            self::VIEWABLE_NODE_ID,
            $relatedIds,
            'a viewable related node must still surface in the navigation context',
        );
    }

    #[Test]
    public function fails_closed_and_drops_all_related_endpoints_when_no_access_handler_is_wired(): void
    {
        // A wiring gap must withhold every disclosed endpoint rather than leak.
        $handler = $this->handler(null);

        $context = $handler->buildRelationshipRenderContext(new RelNavStubNode('1', 'Source Node A'), $this->anon());

        self::assertSame([], $this->disclosedRelatedIds($context), 'no handler => no disclosed endpoints (fail closed)');
    }

    private function createRelationshipTable(DBALDatabase $database): void
    {
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
            CREATE TABLE relationship (
              rid INTEGER PRIMARY KEY,
              relationship_type TEXT NOT NULL,
              from_entity_type TEXT NOT NULL,
              from_entity_id TEXT NOT NULL,
              to_entity_type TEXT NOT NULL,
              to_entity_id TEXT NOT NULL,
              directionality TEXT NOT NULL DEFAULT 'directed',
              status INTEGER NOT NULL DEFAULT 1,
              weight REAL DEFAULT NULL,
              confidence REAL DEFAULT NULL,
              start_date INTEGER DEFAULT NULL,
              end_date INTEGER DEFAULT NULL
            )
            SQL);
    }

    private function insertRelationship(DBALDatabase $database, int $rid, string $fromType, string $fromId, string $toType, string $toId): void
    {
        $database->query(
            'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, 'references', $fromType, $fromId, $toType, $toId, 'directed', 1],
        );
    }
}

/**
 * Minimal published-node stub — carries the `status`/`workflow_state` the
 * WorkflowVisibilityFilter reads (so it counts as publicly visible, reproducing
 * the pre-fix leak) plus an id/label for the access gate and disclosure.
 */
final class RelNavStubNode implements EntityInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return 'node-uuid-' . $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }

    public function bundle(): string
    {
        return 'article';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return $this->toArray()[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        return $this;
    }

    public function toArray(): array
    {
        return [
            'nid' => $this->id,
            'title' => $this->label,
            'type' => 'article',
            'status' => 1,
            'workflow_state' => 'published',
        ];
    }

    public function language(): string
    {
        return 'en';
    }
}

/**
 * @internal test double
 */
final class RelNavNodeStorage implements EntityStorageInterface
{
    /** @param array<string, RelNavStubNode> $entities */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[(string) $id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $loaded = [];
        foreach ($ids as $id) {
            $resolved = (string) $id;
            if (isset($this->entities[$resolved])) {
                $loaded[$resolved] = $this->entities[$resolved];
            }
        }

        return $loaded;
    }

    public function save(EntityInterface $entity): int
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function delete(array $entities): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getQuery(): EntityQueryInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }
}

/**
 * @internal test double
 */
final class RelNavRelationshipStorage implements EntityStorageInterface
{
    /** @param array<int, Relationship> $entities */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[(int) $id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $loaded = [];
        foreach ($ids as $id) {
            $resolved = (int) $id;
            if (isset($this->entities[$resolved])) {
                $loaded[$resolved] = $this->entities[$resolved];
            }
        }

        return $loaded;
    }

    public function save(EntityInterface $entity): int
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function delete(array $entities): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getQuery(): EntityQueryInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getEntityTypeId(): string
    {
        return 'relationship';
    }
}
