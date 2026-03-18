<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase14;

require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipDiscoveryService.php';
require_once __DIR__ . '/../../../packages/relationship/src/Relationship.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipSchemaManager.php';

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

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
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $definition) use ($dispatcher): SqlEntityStorage {
                $schema = new SqlSchemaHandler($definition, $this->database);
                $schema->ensureTable();
                return new SqlEntityStorage($definition, $this->database, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'boolean'],
                'workflow_state' => ['type' => 'string'],
            ],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
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
        (new RelationshipSchemaManager($this->database))->ensure();

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
    public function sharedDiscoveryFixturesDriveSearchRelationshipAndMcpConsumers(): void
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
            new RelationshipTraversalService($this->entityTypeManager, $this->database),
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

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: null,
        );
        $rpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 77,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'water',
                    'type' => 'node',
                    'limit' => 10,
                    'anchor_type' => 'node',
                    'anchor_id' => (string) $anchorId,
                ],
            ],
        ]);
        $payload = json_decode((string) $rpc['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame(4, $payload['data']['graph_context']['counts']['total']);
        $this->assertNotEmpty($payload['data']['recommendations']);
        $this->assertSame('published_only', $payload['data']['recommendations'][0]['explanation']['visibility_contract']);
    }

    #[Test]
    public function sharedDiscoveryFixturesEnforceNonPublicAnchorErrors(): void
    {
        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: null,
        );
        $draftId = $this->nodeIdsByFixtureKey['governance_draft'];
        $rpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 88,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'governance',
                    'type' => 'node',
                    'anchor_type' => 'node',
                    'anchor_id' => (string) $draftId,
                ],
            ],
        ]);

        $this->assertSame(-32000, $rpc['error']['code']);
        $this->assertStringContainsString('not visible', $rpc['error']['message']);
    }

    private function seedFixtureCorpus(): void
    {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        foreach (WorkflowFixturePack::discoveryNodes() as $key => $values) {
            $entity = $nodeStorage->create($values);
            $nodeStorage->save($entity);
            $this->nodeIdsByFixtureKey[$key] = $entity->id();
        }

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        (new RelationshipSchemaManager($this->database))->ensure();
        foreach (WorkflowFixturePack::discoveryRelationships() as $fixture) {
            $relationship = $relationshipStorage->create([
                'relationship_type' => $fixture['relationship_type'],
                'from_entity_type' => 'node',
                'from_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['from']],
                'to_entity_type' => 'node',
                'to_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['to']],
                'status' => $fixture['status'],
                'start_date' => $fixture['start_date'],
                'end_date' => $fixture['end_date'],
            ]);
            $relationshipStorage->save($relationship);
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

        return (int) ($entity->toArray()['status'] ?? 0) === 1
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

        return (int) ($entity->toArray()['status'] ?? 0) === 1
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
