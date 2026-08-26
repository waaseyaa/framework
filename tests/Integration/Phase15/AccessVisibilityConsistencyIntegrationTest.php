<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase15;

require_once __DIR__ . '/../../../packages/relationship/src/VisibilityFilterInterface.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/Relationship.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipSchemaManager.php';
require_once __DIR__ . '/../../../packages/workflows/src/WorkflowVisibilityFilter.php';

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Workflows\WorkflowVisibilityFilter;

#[CoversNothing]
final class AccessVisibilityConsistencyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        EntityReadRuntime::installFieldRegistry(null);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installFieldRegistry(null);
    }

    #[Test]
    public function forwardDraftRemainsVisibleThroughItsPublishedServingProjection(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $resolver = new SingleConnectionResolver($database);
        $manager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: legacy SqlEntityStorage engine is deleted; persistence goes
            // exclusively through the repository factory below.
            null,
            // C-22: repository factory mirroring the kernel's getRepository() shape.
            function (string $_id, EntityType $definition) use ($dispatcher, $resolver, $database): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($definition, new SqlStorageDriver($resolver, $idKey), $dispatcher, database: $database);
            },
        );

        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: \Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
                'workflow_state' => ['type' => 'string'],
            ],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            _fieldDefinitions: [
                'relationship_type' => ['type' => 'string'],
                'from_entity_type' => ['type' => 'string'],
                'from_entity_id' => ['type' => 'string'],
                'to_entity_type' => ['type' => 'string'],
                'to_entity_id' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
            ],
        ));
        $manager->getRepository('relationship');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::relationship($database);
        new RelationshipSchemaManager($database)->ensure();

        $nodeRepository = $manager->getRepository('node');
        $anchor = $nodeRepository->create([
            'title' => 'Published Anchor',
            'body' => 'anchor context',
            'type' => 'teaching',
            'status' => 1,
            'workflow_state' => 'published',
        ]);
        $nodeRepository->save($anchor, validate: false);

        $review = $nodeRepository->create([
            'title' => 'Forward Draft',
            'body' => 'published projection',
            'type' => 'teaching',
            'status' => 'published',
            'workflow_state' => 'review',
        ]);
        $nodeRepository->save($review, validate: false);

        $relationshipRepository = $manager->getRepository('relationship');
        $relationship = $relationshipRepository->create([
            'relationship_type' => 'related',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $anchor->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $review->id(),
            'status' => 1,
        ]);
        $relationshipRepository->save($relationship, validate: false);

        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = new SqliteEmbeddingStorage($database->getConnection()->getNativeConnection());

        $search = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );
        $searchPayload = $search->search('Forward', 'node', 10)->toArray();
        $this->assertSame('keyword', $searchPayload['meta']['mode']);
        $this->assertCount(1, $searchPayload['data']);
        $this->assertSame('Forward Draft', $searchPayload['data'][0]['attributes']['title']);

        $traversal = new RelationshipTraversalService($manager, $database, new WorkflowVisibilityFilter());
        $browse = $traversal->browse('node', (string) $anchor->id(), ['status' => 'published']);
        $this->assertCount(1, $browse['outbound']);
        $this->assertSame('Forward Draft', $browse['outbound'][0]['related_entity_label']);
        $this->assertSame([], $browse['inbound']);
    }
}
