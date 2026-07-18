<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase14;

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
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Policy\PublishedContentStatusReader;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
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
final class DiscoveryFixtureConsumersIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private SqliteEmbeddingStorage $embeddingStorage;

    /** @var array<string, int|string> */
    private array $nodeIdsByFixtureKey = [];

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: legacy SqlEntityStorage engine is deleted; persistence goes
            // exclusively through the repository factory below.
            null,
            // C-22: repository factory mirroring the kernel's getRepository() shape
            // — wires the access handler into getQuery() as production does
            // (#1714); under deny-by-default (C-6) an unwired query layer
            // denies every candidate before the consumer's filter runs.
            function (string $_id, EntityType $definition) use ($dispatcher, $resolver): EntityRepository {
                new SqlSchemaHandler($definition, $this->database)->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';

                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $this->database,
                    accessHandlerResolver: fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
                );
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: \Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                'status' => ['type' => 'boolean', 'read' => FieldReadLevel::Public],
                'workflow_state' => ['type' => 'string', 'read' => FieldReadLevel::Public],
            ],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
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
        new RelationshipSchemaManager($this->database)->ensure();

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->accessHandler = new EntityAccessHandler([
            new DiscoveryFixtureNodeViewPolicy(),
            new DiscoveryFixtureRelationshipViewPolicy(),
        ]);
        $this->account = new DiscoveryFixtureAnonymousAccount();
        $this->embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());
        $this->seedFixtureCorpus();
    }

    #[Test]
    public function sharedDiscoveryFixturesDriveSearchAndRelationshipConsumers(): void
    {
        $search = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: null,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );
        $searchPayload = $search->search('water', 'node', 10)->toArray();

        $this->assertSame('keyword', $searchPayload['meta']['mode']);
        $searchTitles = array_map(
            static fn(array $resource): string => (string) ($resource['attributes']['title'] ?? ''),
            $searchPayload['data'],
        );
        $this->assertContains('Water Teaching Anchor', $searchTitles);
        $this->assertNotContains('Governance Draft', $searchTitles);
        $this->assertNotContains('Archive Song', $searchTitles);

        $anchorId = $this->nodeIdsByFixtureKey['anchor_water'];
        $relationshipDiscovery = new RelationshipDiscoveryService(
            new RelationshipTraversalService(
                $this->entityTypeManager,
                $this->database,
                new WorkflowVisibilityFilter(),
            ),
        );

        $hub = $relationshipDiscovery->topicHub('node', $anchorId, ['status' => 'published']);
        $this->assertSame(4, $hub['page']['total']);
        $facetCounts = [];
        foreach ($hub['facets']['relationship_types'] as $facet) {
            $facetCounts[(string) $facet['key']] = (int) $facet['count'];
        }
        $this->assertSame(2, $facetCounts['related'] ?? null);
        $this->assertSame(1, $facetCounts['supports'] ?? null);
        $this->assertSame(1, $facetCounts['temporal'] ?? null);

        $timeline = $relationshipDiscovery->timeline('node', $anchorId, [
            'status' => 'published',
            'direction' => 'both',
            'from' => WorkflowFixturePack::FIXED_TIMESTAMP - 200000,
            'to' => WorkflowFixturePack::FIXED_TIMESTAMP + 200000,
        ]);
        $this->assertSame(4, $timeline['page']['total']);
        $this->assertNotEmpty($timeline['items']);
    }

    private function seedFixtureCorpus(): void
    {
        $nodeRepository = $this->entityTypeManager->getRepository('node');
        foreach (WorkflowFixturePack::discoveryNodes() as $key => $values) {
            $entity = $nodeRepository->create($values);
            $nodeRepository->save($entity, validate: false);
            $this->nodeIdsByFixtureKey[$key] = $entity->id();
        }

        $relationshipRepository = $this->entityTypeManager->getRepository('relationship');
        new RelationshipSchemaManager($this->database)->ensure();
        foreach (WorkflowFixturePack::discoveryRelationships() as $fixture) {
            $relationship = $relationshipRepository->create([
                'relationship_type' => $fixture['relationship_type'],
                'from_entity_type' => 'node',
                'from_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['from']],
                'to_entity_type' => 'node',
                'to_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['to']],
                'status' => $fixture['status'],
                'start_date' => $fixture['start_date'],
                'end_date' => $fixture['end_date'],
            ]);
            $relationshipRepository->save($relationship, validate: false);
        }
    }
}

final class DiscoveryFixtureAnonymousAccount implements AccountInterface
{
    public function id(): int|string
    {
        return 0;
    }
    public function hasPermission(string $permission): bool
    {
        return false;
    }
    public function getRoles(): array
    {
        return ['anonymous'];
    }
    public function isAuthenticated(): bool
    {
        return false;
    }
}

final class DiscoveryFixtureNodeViewPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        return new PublishedContentStatusReader()->isPublished($entity)
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}

final class DiscoveryFixtureRelationshipViewPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        return new PublishedContentStatusReader()->isPublished($entity)
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
