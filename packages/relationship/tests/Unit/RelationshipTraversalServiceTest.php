<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

require_once __DIR__ . '/../../src/Relationship.php';
require_once __DIR__ . '/../../src/RelationshipTraversalService.php';

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipTraversalService;

#[CoversClass(RelationshipTraversalService::class)]
final class RelationshipTraversalServiceTest extends TestCase
{
    public function testBrowsePublishedExcludesEdgesToUnpublishedNodeEndpoints(): void
    {
        $database = PdoDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
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
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipTraversalService($manager, $database);

        $result = $service->browse('node', 1, ['status' => 'published']);

        $this->assertSame(1, $result['counts']['total']);
        $this->assertCount(1, $result['outbound']);
        $this->assertSame('3', $result['outbound'][0]['related_entity_id']);
    }

    public function testBrowseAllIncludesMixedStateEndpointsDeterministically(): void
    {
        $database = PdoDatabase::createSqlite();
        $this->createRelationshipTable($database);

        $this->insertRelationship($database, 1, 'node', '1', 'node', '2', 1);
        $this->insertRelationship($database, 2, 'node', '1', 'node', '3', 1);

        $relationshipStorage = new TraversalRelationshipStorage([
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
        ]);
        $nodeStorage = new TraversalEntityStorage([
            '2' => new TraversalTestEntity('node', 'article', 2, 'Draft Node', [
                'nid' => 2,
                'type' => 'article',
                'status' => 0,
                'workflow_state' => 'draft',
            ]),
            '3' => new TraversalTestEntity('node', 'article', 3, 'Published Node', [
                'nid' => 3,
                'type' => 'article',
                'status' => 1,
                'workflow_state' => 'published',
            ]),
        ]);
        $manager = new TraversalEntityTypeManager([
            'relationship' => $relationshipStorage,
            'node' => $nodeStorage,
        ]);

        $service = new RelationshipTraversalService($manager, $database);
        $result = $service->browse('node', 1, ['status' => 'all']);

        $this->assertSame(2, $result['counts']['total']);
        $this->assertCount(2, $result['outbound']);
        $this->assertSame('2', $result['outbound'][0]['related_entity_id']);
        $this->assertSame('3', $result['outbound'][1]['related_entity_id']);
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
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        int $status,
    ): void {
        $database->query(
            'INSERT INTO relationship (rid, relationship_type, from_entity_type, from_entity_id, to_entity_type, to_entity_id, directionality, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$rid, 'references', $fromType, $fromId, $toType, $toId, 'directed', $status],
        );
    }
}

final class TraversalEntityTypeManager implements EntityTypeManagerInterface
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

final class TraversalRelationshipStorage implements EntityStorageInterface
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
        $resolved = (int) $id;
        return $this->entities[$resolved] ?? null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $result = [];
        foreach ($ids as $id) {
            $resolved = (int) $id;
            if (isset($this->entities[$resolved])) {
                $result[$resolved] = $this->entities[$resolved];
            }
        }

        return $result;
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

final class TraversalEntityStorage implements EntityStorageInterface
{
    /**
     * @param array<string, TraversalTestEntity> $entities
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
        $result = [];
        foreach ($ids as $id) {
            $stringId = (string) $id;
            if (isset($this->entities[$stringId])) {
                $result[$stringId] = $this->entities[$stringId];
            }
        }

        return $result;
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

final class TraversalTestEntity implements EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $bundle,
        private readonly int|string $id,
        private readonly string $label,
        private readonly array $values,
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
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
