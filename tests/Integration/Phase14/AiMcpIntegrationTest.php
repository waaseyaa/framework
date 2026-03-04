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
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Queue\InMemoryQueue;

#[CoversNothing]
final class AiMcpIntegrationTest extends TestCase
{
    private PdoDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private SqliteEmbeddingStorage $embeddingStorage;
    private InMemoryQueue $queue;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private ResourceSerializer $serializer;
    private EmbeddingProviderInterface $provider;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
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

        $this->embeddingStorage = new SqliteEmbeddingStorage($this->database->getPdo());
        $this->queue = new InMemoryQueue();
        $this->account = new AnonymousTestAccount();
        $this->accessHandler = new EntityAccessHandler([new PublishedNodeViewPolicy()]);
        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->provider = new DeterministicEmbeddingProvider();
    }

    #[Test]
    public function fullAiToMcpFlowWorksWithFallbackAndAccessControl(): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $nodeA = $storage->create([
            'title' => 'teaching A',
            'body' => 'water wisdom',
            'type' => 'teaching',
            'status' => 1,
        ]);
        $storage->save($nodeA);

        $nodeB = $storage->create([
            'title' => 'teaching B',
            'body' => 'fire wisdom',
            'type' => 'teaching',
            'status' => 0,
        ]);
        $storage->save($nodeB);

        // 1) Entity save -> listener dispatches embedding job.
        $listener = new EntityEmbeddingListener($this->queue);
        $listener->onPostSave(new \Waaseyaa\Entity\Event\EntityEvent($nodeA));
        $listener->onPostSave(new \Waaseyaa\Entity\Event\EntityEvent($nodeB));
        $messages = $this->queue->getMessages();
        $this->assertCount(2, $messages);

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
        $this->assertContains('search_teachings', $toolNames);
        $this->assertContains('get_entity', $toolNames);
        $this->assertContains('list_entity_types', $toolNames);

        // 6) MCP tool call search_teachings returns JSON:API list.
        $searchRpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_teachings',
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
}

final class AnonymousTestAccount implements AccountInterface
{
    public function id(): int|string { return 0; }
    public function hasPermission(string $permission): bool { return false; }
    public function getRoles(): array { return ['anonymous']; }
    public function isAuthenticated(): bool { return false; }
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
