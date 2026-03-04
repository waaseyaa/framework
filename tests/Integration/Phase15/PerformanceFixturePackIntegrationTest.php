<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase15;

require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipDiscoveryService.php';
require_once __DIR__ . '/../../../packages/relationship/src/Relationship.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipSchemaManager.php';

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

#[CoversNothing]
final class PerformanceFixturePackIntegrationTest extends TestCase
{
    #[Test]
    public function performanceFixtureExpansionIsDeterministicAndMixedWorkflow(): void
    {
        $nodes = WorkflowFixturePack::performanceNodesLargeGraph();
        $relationships = WorkflowFixturePack::performanceRelationshipsLargeGraph();
        $snapshotA = WorkflowFixturePack::corpusSnapshot();
        $snapshotB = WorkflowFixturePack::corpusSnapshot();

        $this->assertCount(48, $nodes);
        $this->assertGreaterThanOrEqual(80, count($relationships));
        $states = array_values(array_unique(array_map(
            static fn(array $values): string => (string) ($values['workflow_state'] ?? ''),
            $nodes,
        )));
        sort($states);
        $this->assertSame(['archived', 'draft', 'published', 'review'], $states);
        $this->assertSame($snapshotA, $snapshotB);
    }

    #[Test]
    public function performanceTraversalScenariosDriveHighFanoutDiscoveryReads(): void
    {
        $database = PdoDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $definition) use ($dispatcher, $database): SqlEntityStorage {
                $schema = new SqlSchemaHandler($definition, $database);
                $schema->ensureTable();
                return new SqlEntityStorage($definition, $database, $dispatcher);
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'boolean'],
                'workflow_state' => ['type' => 'string'],
                'type' => ['type' => 'string'],
            ],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string'],
                'from_entity_type' => ['type' => 'string'],
                'from_entity_id' => ['type' => 'string'],
                'to_entity_type' => ['type' => 'string'],
                'to_entity_id' => ['type' => 'string'],
                'status' => ['type' => 'boolean'],
                'start_date' => ['type' => 'integer'],
                'end_date' => ['type' => 'integer'],
            ],
        ));
        (new RelationshipSchemaManager($database))->ensure();

        $nodeStorage = $entityTypeManager->getStorage('node');
        $nodeIdsByKey = [];
        foreach (WorkflowFixturePack::performanceNodesLargeGraph() as $key => $values) {
            $entity = $nodeStorage->create($values);
            $nodeStorage->save($entity);
            $nodeIdsByKey[$key] = (string) $entity->id();
        }

        $relationshipStorage = $entityTypeManager->getStorage('relationship');
        (new RelationshipSchemaManager($database))->ensure();
        foreach (WorkflowFixturePack::performanceRelationshipsLargeGraph() as $fixture) {
            $relationship = $relationshipStorage->create([
                'relationship_type' => $fixture['relationship_type'],
                'from_entity_type' => 'node',
                'from_entity_id' => $nodeIdsByKey[$fixture['from']],
                'to_entity_type' => 'node',
                'to_entity_id' => $nodeIdsByKey[$fixture['to']],
                'status' => $fixture['status'],
                'start_date' => $fixture['start_date'],
                'end_date' => $fixture['end_date'],
            ]);
            $relationshipStorage->save($relationship);
        }

        $discovery = new RelationshipDiscoveryService(
            new RelationshipTraversalService($entityTypeManager, $database),
        );

        foreach (WorkflowFixturePack::performanceTraversalScenarios() as $scenario) {
            $anchorId = $nodeIdsByKey[$scenario['anchor_key']] ?? null;
            $this->assertNotNull($anchorId, sprintf('Missing anchor key: %s', $scenario['anchor_key']));

            $hub = $discovery->topicHub('node', (string) $anchorId, [
                'status' => $scenario['status'],
                'limit' => $scenario['limit'],
            ]);

            $this->assertGreaterThanOrEqual(
                $scenario['expected_min_total'],
                (int) ($hub['page']['total'] ?? 0),
                sprintf('Scenario failed: %s', $scenario['name']),
            );
        }
    }

    #[Test]
    public function performanceCacheInvalidationScenariosReferenceKnownFixtureKeys(): void
    {
        $nodeKeys = array_keys(WorkflowFixturePack::performanceNodesLargeGraph());
        $relationshipKeys = array_map(
            static fn(array $fixture): string => (string) ($fixture['key'] ?? ''),
            WorkflowFixturePack::performanceRelationshipsLargeGraph(),
        );

        foreach (WorkflowFixturePack::performanceCacheInvalidationScenarios() as $scenario) {
            $entityType = (string) ($scenario['mutate_entity_type'] ?? '');
            $mutateKey = (string) ($scenario['mutate_key'] ?? '');

            if ($entityType === 'node') {
                $this->assertContains($mutateKey, $nodeKeys, sprintf('Unknown node key in scenario: %s', $scenario['name']));
                continue;
            }

            if ($entityType === 'relationship') {
                $this->assertContains($mutateKey, $relationshipKeys, sprintf('Unknown relationship key in scenario: %s', $scenario['name']));
                continue;
            }

            $this->fail(sprintf('Unknown mutate_entity_type in scenario: %s', $scenario['name']));
        }
    }
}
