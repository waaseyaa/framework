<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase14;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Pipeline\EmbeddingPipeline;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
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
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

#[CoversNothing]
final class AiMcpIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private SqliteEmbeddingStorage $embeddingStorage;
    private InMemoryQueue $queue;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private ResourceSerializer $serializer;
    private EmbeddingProviderInterface $provider;

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
            ],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'type'],
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string'],
                'from_entity_type' => ['type' => 'string'],
                'from_entity_id' => ['type' => 'string'],
                'to_entity_type' => ['type' => 'string'],
                'to_entity_id' => ['type' => 'string'],
                'status' => ['type' => 'boolean'],
            ],
        ));

        $this->embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());
        $this->queue = new InMemoryQueue();
        $this->account = new AnonymousTestAccount();
        $this->accessHandler = new EntityAccessHandler([new PublishedNodeViewPolicy(), new PublishedRelationshipViewPolicy()]);
        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->provider = new DeterministicEmbeddingProvider();
    }

    #[Test]
    public function fullAiToMcpFlowWorksWithFallbackAndAccessControl(): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $fixtures = WorkflowFixturePack::aiMcpNodes();
        $nodeA = $storage->create($fixtures['teaching_published']);
        $storage->save($nodeA);

        $nodeB = $storage->create($fixtures['teaching_draft']);
        $storage->save($nodeB);

        // 1) Entity save -> listener dispatches embedding job.
        $listener = new EntityEmbeddingListener($this->queue);
        $listener->onPostSave(new \Waaseyaa\Entity\Event\EntityEvent($nodeA));
        $listener->onPostSave(new \Waaseyaa\Entity\Event\EntityEvent($nodeB));
        $messages = $this->queue->getMessages();
        $this->assertCount(1, $messages);

        // 2) Embedding pipeline stores vectors.
        $pipeline = new EmbeddingPipeline(
            storage: $this->embeddingStorage,
            config: ['ai' => ['embedding_fields' => ['node' => ['title', 'body']]]],
            provider: $this->provider,
        );
        $pipeline->processEntity($nodeA);
        $pipeline->processEntity($nodeB);

        // 3) Semantic search returns the most similar *published* entity.
        $searchController = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->provider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );
        $semantic = $searchController->search('teaching A', 'node', 5)->toArray();
        $this->assertCount(1, $semantic['data']);
        $expectedResourceId = $nodeA->uuid() !== '' ? $nodeA->uuid() : (string) $nodeA->id();
        $this->assertSame($expectedResourceId, $semantic['data'][0]['id']);

        // 4) Keyword fallback works with no provider.
        $fallback = (new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: null,
            accessHandler: $this->accessHandler,
            account: $this->account,
        ))->search('teaching', 'node', 5)->toArray();
        $this->assertSame('keyword', $fallback['meta']['mode']);
        $this->assertCount(1, $fallback['data']);

        // 5) MCP manifest includes required tools.
        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->provider,
        );
        $manifest = $mcp->manifest();
        $toolNames = array_column($manifest['tools'], 'name');
        $this->assertContains('search_entities', $toolNames);
        $this->assertContains('get_entity', $toolNames);
        $this->assertContains('list_entity_types', $toolNames);

        // 6) MCP tool call search_entities returns JSON:API list.
        $searchRpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_entities',
                'arguments' => ['query' => 'teaching A', 'type' => 'node', 'limit' => 5],
            ],
        ]);
        $searchText = $searchRpc['result']['content'][0]['text'];
        $searchDecoded = json_decode($searchText, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $searchDecoded['data']);

        // 7) MCP tool call get_entity returns the correct entity.
        $entityRpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_entity',
                'arguments' => ['type' => 'node', 'id' => $nodeA->id()],
            ],
        ]);
        $entityText = $entityRpc['result']['content'][0]['text'];
        $entityDecoded = json_decode($entityText, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedResourceId, $entityDecoded['data']['id']);
    }

    #[Test]
    public function aiDiscoveryToolBlendsSemanticAndGraphContextDeterministically(): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $fixtures = WorkflowFixturePack::aiMcpNodes();
        $anchor = $storage->create([
            'title' => 'Anchor teaching',
            'body' => 'anchor context',
            'type' => 'teaching',
            'status' => 1,
            'workflow_state' => 'published',
        ]);
        $storage->save($anchor);

        $published = $storage->create($fixtures['teaching_published']);
        $storage->save($published);
        $draft = $storage->create($fixtures['teaching_draft']);
        $storage->save($draft);

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        $relationship = $relationshipStorage->create([
            'relationship_type' => 'related',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $anchor->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $published->id(),
            'status' => 1,
        ]);
        $relationshipStorage->save($relationship);

        $pipeline = new EmbeddingPipeline(
            storage: $this->embeddingStorage,
            config: ['ai' => ['embedding_fields' => ['node' => ['title', 'body']]]],
            provider: $this->provider,
        );
        $pipeline->processEntity($published);
        $pipeline->processEntity($draft);
        $pipeline->processEntity($anchor);

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->provider,
        );

        $response = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'teaching A',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => (string) $anchor->id(),
                ],
            ],
        ]);

        $payload = json_decode((string) $response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('semantic', $payload['meta']['mode']);
        $this->assertSame(2, $payload['meta']['count']);
        $this->assertCount(2, $payload['data']['recommendations']);
        $this->assertSame('published_only', $payload['data']['recommendations'][0]['explanation']['visibility_contract']);
        $this->assertSame('node', $payload['data']['graph_context']['source']['type']);
        $this->assertSame((string) $anchor->id(), $payload['data']['graph_context']['source']['id']);
        $this->assertSame(1, $payload['data']['graph_context']['counts']['total']);
        $this->assertSame(1, $payload['data']['graph_context']['relationship_types']['related']);
    }

    #[Test]
    public function aiDiscoveryToolReturnsExecutionErrorForNonPublicAnchor(): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $draftAnchor = $storage->create([
            'title' => 'Draft anchor',
            'body' => 'hidden',
            'type' => 'teaching',
            'status' => 0,
            'workflow_state' => 'draft',
        ]);
        $storage->save($draftAnchor);

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->provider,
        );

        $response = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 31,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'teaching',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => (string) $draftAnchor->id(),
                ],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('not visible', $response['error']['message']);
    }

    #[Test]
    public function traversalToolReturnsExecutionErrorWhenSourceNodeIsNotVisible(): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $fixtures = WorkflowFixturePack::aiMcpNodes();
        $published = $storage->create($fixtures['teaching_published']);
        $storage->save($published);
        $draft = $storage->create($fixtures['teaching_draft']);
        $storage->save($draft);

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        $relationship = $relationshipStorage->create([
            'relationship_type' => 'related',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $draft->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $published->id(),
            'status' => 1,
        ]);
        $relationshipStorage->save($relationship);

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->provider,
        );

        $response = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 32,
            'method' => 'tools/call',
            'params' => [
                'name' => 'traverse_relationships',
                'arguments' => ['type' => 'node', 'id' => (string) $draft->id()],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('source entity is not visible', $response['error']['message']);
    }
}

final class AnonymousTestAccount implements AccountInterface
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

final class PublishedNodeViewPolicy implements AccessPolicyInterface
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

final class DeterministicEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        $normalized = strtolower($text);
        if (str_contains($normalized, 'teaching a') || str_contains($normalized, 'water')) {
            return [1.0, 0.0, 0.0];
        }
        if (str_contains($normalized, 'teaching b') || str_contains($normalized, 'fire')) {
            return [0.0, 1.0, 0.0];
        }

        return [0.0, 0.0, 1.0];
    }
}

final class PublishedRelationshipViewPolicy implements AccessPolicyInterface
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
