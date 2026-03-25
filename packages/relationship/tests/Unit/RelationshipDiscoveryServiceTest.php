<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

require_once __DIR__ . '/../../src/Relationship.php';
require_once __DIR__ . '/../../src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../src/RelationshipDiscoveryService.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipTraversalService;

#[CoversClass(RelationshipDiscoveryService::class)]
final class RelationshipDiscoveryServiceTest extends TestCase
{
    #[Test]
    public function topicHubReturnsDeterministicPaginatedItemsAndFacets(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'references', 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'influences', 'node', '1', 'node', '3', 1);
        $this->insertRelationship($database, 3, 'influences', 'node', '4', 'node', '1', 1);
        $this->insertRelationship($database, 4, 'references', 'node', '1', 'taxonomy_term', '9', 1);

        $relationshipStorage = new DiscoveryRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            3 => new Relationship([
                'rid' => 3,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '4',
                'to_entity_type' => 'node',
                'to_entity_id' => '1',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            4 => new Relationship([
                'rid' => 4,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'taxonomy_term',
                'to_entity_id' => '9',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);

        $nodeStorage = new DiscoveryEntityStorage([
            '1' => new DiscoveryTestEntity('node', 'article', 1, 'Source'),
            '2' => new DiscoveryTestEntity('node', 'article', 2, 'Alpha Node'),
            '3' => new DiscoveryTestEntity('node', 'article', 3, 'Beta Node'),
            '4' => new DiscoveryTestEntity('node', 'article', 4, 'Gamma Node'),
        ]);

        $termStorage = new DiscoveryEntityStorage([
            '9' => new DiscoveryTestEntity('taxonomy_term', 'topic', 9, 'Water'),
        ]);

        $manager = new DiscoveryEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
            'taxonomy_term' => $termStorage,
        ]);

        $service = new RelationshipDiscoveryService(new RelationshipTraversalService($manager, $database));

        $hub = $service->topicHub('node', 1, ['offset' => 1, 'limit' => 2, 'status' => 'published']);

        $this->assertSame(4, $hub['page']['total']);
        $this->assertSame(2, $hub['page']['count']);
        $this->assertSame('influences', $hub['items'][0]['relationship_type']);
        $this->assertSame('inbound', $hub['items'][0]['direction']);
        $this->assertSame('references', $hub['items'][1]['relationship_type']);
        $this->assertSame('node', $hub['items'][1]['related_entity_type']);
        $this->assertSame('influences', $hub['facets']['relationship_types'][0]['key']);
        $this->assertSame(2, $hub['facets']['relationship_types'][0]['count']);
        $this->assertSame('node', $hub['facets']['related_entity_types'][0]['key']);
        $this->assertSame(3, $hub['facets']['related_entity_types'][0]['count']);
    }

    #[Test]
    public function clusterPageGroupsAndOrdersClustersDeterministically(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'references', 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'references', 'node', '1', 'node', '3', 1);
        $this->insertRelationship($database, 3, 'references', 'node', '1', 'taxonomy_term', '9', 1);
        $this->insertRelationship($database, 4, 'influences', 'node', '1', 'node', '4', 1);

        $relationshipStorage = new DiscoveryRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            3 => new Relationship([
                'rid' => 3,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'taxonomy_term',
                'to_entity_id' => '9',
                'directionality' => 'directed',
                'status' => 1,
            ]),
            4 => new Relationship([
                'rid' => 4,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '4',
                'directionality' => 'directed',
                'status' => 1,
            ]),
        ]);

        $nodeStorage = new DiscoveryEntityStorage([
            '1' => new DiscoveryTestEntity('node', 'article', 1, 'Source'),
            '2' => new DiscoveryTestEntity('node', 'article', 2, 'Alpha Node'),
            '3' => new DiscoveryTestEntity('node', 'article', 3, 'Beta Node'),
            '4' => new DiscoveryTestEntity('node', 'article', 4, 'Gamma Node'),
        ]);

        $termStorage = new DiscoveryEntityStorage([
            '9' => new DiscoveryTestEntity('taxonomy_term', 'topic', 9, 'Water'),
        ]);

        $manager = new DiscoveryEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
            'taxonomy_term' => $termStorage,
        ]);

        $service = new RelationshipDiscoveryService(new RelationshipTraversalService($manager, $database));
        $clusterPage = $service->clusterPage('node', 1, ['status' => 'published', 'limit' => 1, 'offset' => 0]);

        $this->assertSame(3, $clusterPage['page']['total']);
        $this->assertCount(1, $clusterPage['clusters']);
        $this->assertSame('references::node', $clusterPage['clusters'][0]['cluster_key']);
        $this->assertSame(2, $clusterPage['clusters'][0]['count']);
        $this->assertSame('Alpha Node', $clusterPage['clusters'][0]['related_entities'][0]['label']);
        $this->assertSame('Beta Node', $clusterPage['clusters'][0]['related_entities'][1]['label']);
    }

    #[Test]
    public function timelineRespectsDirectionAndTemporalWindowFilters(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertTemporalRelationship($database, 1, 'references', 'node', '1', 'node', '2', 1, 100, 200);
        $this->insertTemporalRelationship($database, 2, 'references', 'node', '3', 'node', '1', 1, 150, 260);
        $this->insertTemporalRelationship($database, 3, 'references', 'node', '1', 'node', '4', 1, 300, 400);

        $relationshipStorage = new DiscoveryRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 100,
                'end_date' => 200,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '3',
                'to_entity_type' => 'node',
                'to_entity_id' => '1',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 150,
                'end_date' => 260,
            ]),
            3 => new Relationship([
                'rid' => 3,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '4',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 300,
                'end_date' => 400,
            ]),
        ]);

        $nodeStorage = new DiscoveryEntityStorage([
            '1' => new DiscoveryTestEntity('node', 'article', 1, 'Source'),
            '2' => new DiscoveryTestEntity('node', 'article', 2, 'Alpha Node'),
            '3' => new DiscoveryTestEntity('node', 'article', 3, 'Inbound Node'),
            '4' => new DiscoveryTestEntity('node', 'article', 4, 'Late Node'),
        ]);

        $manager = new DiscoveryEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipDiscoveryService(new RelationshipTraversalService($manager, $database));
        $timeline = $service->timeline('node', 1, [
            'direction' => 'both',
            'from' => 130,
            'to' => 280,
            'status' => 'published',
        ]);

        $this->assertSame(2, $timeline['page']['total']);
        $this->assertSame('1', $timeline['items'][0]['relationship_id']);
        $this->assertSame('outbound', $timeline['items'][0]['direction']);
        $this->assertSame(100, $timeline['items'][0]['timeline_date']);
        $this->assertSame('2', $timeline['items'][1]['relationship_id']);
        $this->assertSame('inbound', $timeline['items'][1]['direction']);
        $this->assertSame(150, $timeline['items'][1]['timeline_date']);
    }

    #[Test]
    public function timelineDeterministicTieBreaksApplyForEqualStartDates(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertTemporalRelationship($database, 1, 'influences', 'node', '1', 'node', '2', 1, 500, null);
        $this->insertTemporalRelationship($database, 2, 'influences', 'node', '1', 'node', '3', 1, 500, null);

        $relationshipStorage = new DiscoveryRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 500,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '3',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 500,
            ]),
        ]);

        $nodeStorage = new DiscoveryEntityStorage([
            '1' => new DiscoveryTestEntity('node', 'article', 1, 'Source'),
            '2' => new DiscoveryTestEntity('node', 'article', 2, 'Alpha'),
            '3' => new DiscoveryTestEntity('node', 'article', 3, 'Beta'),
        ]);

        $manager = new DiscoveryEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipDiscoveryService(new RelationshipTraversalService($manager, $database));
        $timeline = $service->timeline('node', 1, ['direction' => 'outbound', 'status' => 'published']);

        $this->assertSame(2, $timeline['page']['total']);
        $this->assertSame('1', $timeline['items'][0]['relationship_id']);
        $this->assertSame('2', $timeline['items'][1]['relationship_id']);
    }

    #[Test]
    public function endpointPageAndRelationshipEntityPageExposeDirectionalEdgeContext(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertTemporalRelationship($database, 1, 'references', 'node', '1', 'node', '2', 1, 200, 260);
        $this->insertTemporalRelationship($database, 2, 'influences', 'node', '3', 'node', '1', 1, 210, null);

        $relationshipStorage = new DiscoveryRelationshipStorage([
            1 => new Relationship([
                'rid' => 1,
                'relationship_type' => 'references',
                'from_entity_type' => 'node',
                'from_entity_id' => '1',
                'to_entity_type' => 'node',
                'to_entity_id' => '2',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 200,
                'end_date' => 260,
            ]),
            2 => new Relationship([
                'rid' => 2,
                'relationship_type' => 'influences',
                'from_entity_type' => 'node',
                'from_entity_id' => '3',
                'to_entity_type' => 'node',
                'to_entity_id' => '1',
                'directionality' => 'directed',
                'status' => 1,
                'start_date' => 210,
            ]),
        ]);

        $nodeStorage = new DiscoveryEntityStorage([
            '1' => new DiscoveryTestEntity('node', 'article', 1, 'Source'),
            '2' => new DiscoveryTestEntity('node', 'article', 2, 'Target'),
            '3' => new DiscoveryTestEntity('node', 'article', 3, 'Inbound'),
        ]);

        $manager = new DiscoveryEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipDiscoveryService(new RelationshipTraversalService($manager, $database));
        $endpointPage = $service->endpointPage('node', 1, ['status' => 'published']);

        $this->assertSame('node', $endpointPage['endpoint']['type']);
        $this->assertSame('1', $endpointPage['endpoint']['id']);
        $this->assertSame(2, $endpointPage['browse']['counts']['total']);
        $this->assertArrayHasKey('direction', $endpointPage['browse']['outbound'][0]);
        $this->assertArrayHasKey('inverse', $endpointPage['browse']['outbound'][0]);

        $relationshipPage = $service->relationshipEntityPage([
            'relationship_type' => 'references',
            'directionality' => 'directed',
            'status' => 1,
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'start_date' => 200,
            'end_date' => 260,
        ], ['status' => 'published']);

        $this->assertSame('references', $relationshipPage['edge_context']['relationship_type']);
        $this->assertSame('directed', $relationshipPage['edge_context']['directionality']);
        $this->assertSame('node', $relationshipPage['from_endpoint']['endpoint']['type']);
        $this->assertSame('1', $relationshipPage['from_endpoint']['endpoint']['id']);
        $this->assertSame('2', $relationshipPage['to_endpoint']['endpoint']['id']);
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

    private function insertRelationship(
        DBALDatabase $database,
        int $rid,
        string $relationshipType,
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        int $status,
    ): void {
        $database->query(
            'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, $relationshipType, $fromType, $fromId, $toType, $toId, 'directed', $status],
        );
    }

    private function insertTemporalRelationship(
        DBALDatabase $database,
        int $rid,
        string $relationshipType,
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        int $status,
        ?int $startDate,
        ?int $endDate,
    ): void {
        $database->query(
            'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, $relationshipType, $fromType, $fromId, $toType, $toId, 'directed', $status, $startDate, $endDate],
        );
    }
}

final class DiscoveryEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param array<string, EntityStorageInterface> $storages
     */
    public function __construct(private readonly array $storages) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getDefinitions(): array
    {
        return [];
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->storages[$entityTypeId]);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        if (!isset($this->storages[$entityTypeId])) {
            throw new \InvalidArgumentException(sprintf('Storage missing for "%s".', $entityTypeId));
        }

        return $this->storages[$entityTypeId];
    }

    public function registerEntityType(EntityTypeInterface $type): void
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type): void
    {
        throw new \RuntimeException('Not needed in test.');
    }
}

final class DiscoveryRelationshipStorage implements EntityStorageInterface
{
    /**
     * @param array<int, Relationship> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[(int) $id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }

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

final class DiscoveryEntityStorage implements EntityStorageInterface
{
    /**
     * @param array<string, DiscoveryTestEntity> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[(string) $id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }

    public function loadMultiple(array $ids = []): array
    {
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

final class DiscoveryTestEntity implements EntityInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $bundle,
        private readonly int|string $id,
        private readonly string $label,
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return '';
    }

    public function label(): string
    {
        return $this->label;
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function bundle(): string
    {
        return $this->bundle;
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed { return null; }
    public function set(string $name, mixed $value): static { return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => 1,
            'workflow_state' => 'published',
        ];
    }

    public function language(): string
    {
        return 'en';
    }
}
