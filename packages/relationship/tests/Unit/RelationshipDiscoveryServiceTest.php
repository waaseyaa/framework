<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

require_once __DIR__ . '/../../src/Relationship.php';
require_once __DIR__ . '/../../src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../src/RelationshipDiscoveryService.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\PdoDatabase;
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
        $database = PdoDatabase::createSqlite();
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
        $database = PdoDatabase::createSqlite();
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

    private function createRelationshipTable(PdoDatabase $database): void
    {
        $database->getPdo()->exec(<<<SQL
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
        PdoDatabase $database,
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
