<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase15;

require_once __DIR__ . '/../../../packages/relationship/src/VisibilityFilterInterface.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipDiscoveryService.php';
require_once __DIR__ . '/../../../packages/relationship/src/Relationship.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipSchemaManager.php';
require_once __DIR__ . '/../../../packages/workflows/src/WorkflowVisibilityFilter.php';

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Tests\Support\WorkflowFixturePack;
use Waaseyaa\Workflows\WorkflowVisibilityFilter;

#[CoversNothing]
final class PerformanceFixturePackIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);
        EntityReadRuntime::installFieldRegistry(null);
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);
        EntityReadRuntime::installFieldRegistry(null);
    }

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
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $entityTypeManager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: legacy SqlEntityStorage engine is deleted; persistence goes
            // exclusively through the repository factory below.
            null,
            // C-22 WP3: read/write path now goes through the canonical repository.
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $database): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';
                $resolver = new SingleConnectionResolver($database);

                return new EntityRepository($definition, new SqlStorageDriver($resolver, $idKey), $dispatcher, database: $database);
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: \Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                'status' => ['type' => 'boolean', 'read' => FieldReadLevel::Public],
                'workflow_state' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'type' => ['type' => 'string', 'read' => FieldReadLevel::Public],
            ],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            _fieldDefinitions: [
                'relationship_type' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'from_entity_type' => ['type' => 'string', 'settings' => ['authorizationInput' => true], 'read' => FieldReadLevel::Protected],
                'from_entity_id' => ['type' => 'string', 'settings' => ['authorizationInput' => true], 'read' => FieldReadLevel::Protected],
                'to_entity_type' => ['type' => 'string', 'settings' => ['authorizationInput' => true], 'read' => FieldReadLevel::Protected],
                'to_entity_id' => ['type' => 'string', 'settings' => ['authorizationInput' => true], 'read' => FieldReadLevel::Protected],
                'status' => ['type' => 'boolean', 'read' => FieldReadLevel::Protected],
                'start_date' => ['type' => 'integer', 'read' => FieldReadLevel::Protected],
                'end_date' => ['type' => 'integer', 'read' => FieldReadLevel::Protected],
            ],
        ));
        new RelationshipSchemaManager($database)->ensure();

        $nodeRepository = $entityTypeManager->getRepository('node');
        $nodeIdsByKey = [];
        foreach (WorkflowFixturePack::performanceNodesLargeGraph() as $key => $values) {
            $entity = $nodeRepository->create($values);
            $nodeRepository->save($entity, validate: false);
            $nodeIdsByKey[$key] = (string) $entity->id();
        }

        $relationshipRepository = $entityTypeManager->getRepository('relationship');
        new RelationshipSchemaManager($database)->ensure();
        foreach (WorkflowFixturePack::performanceRelationshipsLargeGraph() as $fixture) {
            $relationship = $relationshipRepository->create([
                'relationship_type' => $fixture['relationship_type'],
                'from_entity_type' => 'node',
                'from_entity_id' => $nodeIdsByKey[$fixture['from']],
                'to_entity_type' => 'node',
                'to_entity_id' => $nodeIdsByKey[$fixture['to']],
                'status' => $fixture['status'],
                'start_date' => $fixture['start_date'],
                'end_date' => $fixture['end_date'],
            ]);
            $relationshipRepository->save($relationship, validate: false);
        }

        $discovery = new RelationshipDiscoveryService(
            new RelationshipTraversalService($entityTypeManager, $database, new WorkflowVisibilityFilter()),
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
