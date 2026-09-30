<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\AI\Tools\Vector\VectorSearchTool;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Foundation\Http\Router\SearchRouter;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/** Real migrated storage and real public responses; repository fixtures are synthetic. */
#[CoversClass(DatabaseEmbeddingStorage::class)]
#[CoversClass(SearchController::class)]
#[CoversClass(VectorSearchTool::class)]
#[CoversClass(EntityEmbeddingListener::class)]
#[CoversClass(SemanticIndexWarmer::class)]
#[CoversClass(SearchRouter::class)]
final class PublicSemanticSearchConformanceTest extends TestCase
{
    private DatabaseEmbeddingStorage $storage;
    private EntityRepositoryInterface $repository;
    /** @var array<string, EntityInterface> */
    private array $entities = [];
    private DBALDatabase $database;
    private ?array $queriedIds = null;
    private SingleTypeEntityTypeManager $manager;
    private EmbeddingProviderInterface $provider;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $this->database = $database;
        RuntimeSchemaMigrations::aiVector($database);
        $this->storage = new DatabaseEmbeddingStorage($database);
        $this->repository = $this->createStub(EntityRepositoryInterface::class);
        $this->repository->method('find')->willReturnCallback(fn($id) => $this->entities[$id] ?? null);
        $this->repository->method('findMany')->willReturnCallback(fn(array $ids): array => array_values(array_intersect_key($this->entities, array_fill_keys($ids, true))));
        $query = $this->createStub(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('setAccount')->willReturnSelf();
        $query->method('execute')->willReturnCallback(fn(): array => $this->queriedIds ?? array_keys($this->entities));
        $this->repository->method('getQuery')->willReturn($query);
        $this->manager = new SingleTypeEntityTypeManager(new EntityType(
            id: 'tool_test',
            label: 'Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        ), $this->repository);
        $this->provider = new class implements EmbeddingProviderInterface {
            public function embed(string $text): array
            {
                return [1.0, 0.0];
            }
        };
        $this->account = $this->createStub(\Waaseyaa\Access\AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn('contract');
        $this->account->method('hasPermission')->willReturn(true);
    }

    private function seed(string $id, array $vector = [1.0, 0.0]): void
    {
        $this->entities[$id] = new ToolTestEntity(['id' => $id, 'title' => 'Current metadata ' . $id]);
        $this->storage->store('tool_test', $id, $vector);
    }

    private function controller(?EntityTypeManagerInterface $manager = null): SearchController
    {
        $manager ??= $this->manager;
        return new SearchController($manager, new ResourceSerializer($manager), $this->storage, $this->provider);
    }

    private function tool(?\Closure $storage = null, ?\Closure $provider = null): VectorSearchTool
    {
        return new VectorSearchTool($this->manager, $provider ?? fn() => $this->provider, $storage ?? fn() => $this->storage);
    }

    private function accessHandler(?string $denied = null): EntityAccessHandler
    {
        return new EntityAccessHandler([new class ($denied) implements AccessPolicyInterface {
            public function __construct(private readonly ?string $denied) {}
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return (string) $entity->id() === $this->denied ? AccessResult::forbidden() : AccessResult::allowed();
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        }]);
    }

    private function assertSchema(array $payload, string $relative): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 4) . '/' . $relative), false, 512, JSON_THROW_ON_ERROR);
        $result = new Validator()->validate(json_decode(json_encode($payload, JSON_THROW_ON_ERROR)), $schema);
        self::assertTrue($result->isValid(), json_encode($result->error()?->keyword(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function real_storage_controller_and_tool_preserve_exact_ids_scores_and_order(): void
    {
        $this->seed('01');
        $this->seed('1');
        $this->seed('opposite', [-1.0, 0.0]);
        $this->storage->store('tool_test', 'deleted', [1.0, 0.0]);
        $http = $this->controller()->search(' find ', 'tool_test')->toArray();
        $this->assertSchema($http, 'packages/ai-vector/resources/semantic-search.schema.json');
        self::assertSame(['01', '1', 'opposite'], array_column($http['data'], 'id'));
        self::assertSame([1.0, 1.0, -1.0], array_column($http['meta']['scores'], 'score'));
        self::assertSame('find', $http['meta']['query']);

        $tool = $this->tool()->execute(['query' => 'find', 'entity_type' => 'tool_test'], $this->account);
        self::assertFalse($tool->isError);
        $this->assertSchema($tool->structuredContent, 'packages/ai-tools/resources/vector-search.schema.json');
        self::assertSame(['01', '1', 'opposite'], array_column($tool->structuredContent['results'], 'id'));
        $wire = json_decode($tool->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Current metadata 01', $wire['results'][0]['metadata']['title']);
        self::assertArrayNotHasKey('vector', $wire['results'][0]);
    }

    #[Test]
    public function real_router_response_conforms_and_invalid_route_input_refuses(): void
    {
        $this->seed('01');
        $router = new SearchRouter(fn() => [$this->storage, $this->provider], $this->manager, $this->accessHandler());
        $request = Request::create('/api/search?q=find&type=tool_test');
        $request->attributes->set('_account', $this->account);
        RuntimeSchemaMigrations::broadcast($this->database);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($this->database));
        $response = $router->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $this->assertSchema(json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR), 'packages/ai-vector/resources/semantic-search.schema.json');
        $request->query->remove('q');
        $response = $router->handle($request);
        self::assertSame(400, $response->getStatusCode());
        $this->assertSchema(json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR), 'packages/ai-vector/resources/semantic-search-error.schema.json');
    }

    #[Test]
    public function empty_results_invalid_inputs_and_failures_have_declared_shapes(): void
    {
        $empty = $this->controller()->search('none', 'tool_test')->toArray();
        $this->assertSchema($empty, 'packages/ai-vector/resources/semantic-search.schema.json');
        self::assertSame([], $empty['data']);
        self::assertSame([], $empty['meta']['scores']);
        $tool = $this->tool()->execute(['query' => 'none'], $this->account);
        self::assertFalse($tool->isError);
        $this->assertSchema($tool->structuredContent, 'packages/ai-tools/resources/vector-search.schema.json');
        self::assertSame([], $tool->structuredContent['results']);
        foreach ([['query' => ' '], ['query' => 'x', 'limit' => 0], ['query' => 'x', 'limit' => '2'], ['query' => 'x', 'entity_type' => 'absent']] as $input) {
            self::assertTrue($this->tool()->execute($input, $this->account)->isError);
        }
        foreach ([[' ', 'tool_test', 400], ['x', 'absent', 404]] as [$query, $type, $status]) {
            $document = $this->controller()->search($query, $type);
            self::assertSame($status, $document->statusCode);
            $this->assertSchema($document->toArray(), 'packages/ai-vector/resources/semantic-search-error.schema.json');
        }
        $this->storage = new DatabaseEmbeddingStorage(DBALDatabase::createSqlite(':memory:'));
        $document = $this->controller()->search('x', 'tool_test');
        self::assertSame(503, $document->statusCode);
        $this->assertSchema($document->toArray(), 'packages/ai-vector/resources/semantic-search-error.schema.json');
        self::assertStringNotContainsString('embeddings', json_encode($document->toArray()));
        self::assertTrue($this->tool()->execute(['query' => 'x'], $this->account)->isError);
        $error = $this->tool(provider: static fn() => throw new \RuntimeException('secret-dsn'))->execute(['query' => 'x'], $this->account);
        self::assertTrue($error->isError);
        self::assertStringNotContainsString('secret-dsn', json_encode($error));
    }

    #[Test]
    public function incompatible_host_storage_results_are_refused_by_both_public_consumers(): void
    {
        $valid = ['id' => '1', 'score' => 1.0];
        foreach ([[$valid, $valid], [['id' => '1', 'score' => INF]], [$valid + ['metadata' => []]], [['id' => 'z', 'score' => 1.0], ['id' => 'a', 'score' => 1.0]]] as $matches) {
            $storage = new class ($matches) implements \Waaseyaa\AI\Vector\EmbeddingStorageInterface {
                public function __construct(private readonly array $matches) {}
                public function store(string $entityType, string $id, array $vector): void {}
                public function delete(string $entityType, string $id): void {}
                public function findSimilar(array $queryVector, string $entityType, int $limit): array
                {
                    return $this->matches;
                }
            };
            $controller = new SearchController($this->manager, new ResourceSerializer($this->manager), $storage, $this->provider);
            $response = $controller->search('x', 'tool_test');
            self::assertSame(503, $response->statusCode);
            $this->assertSchema($response->toArray(), 'packages/ai-vector/resources/semantic-search-error.schema.json');
            self::assertTrue($this->tool(storage: fn() => $storage)->execute(['query' => 'x', 'entity_type' => 'tool_test'], $this->account)->isError);
        }
    }

    #[Test]
    public function schema_rejects_missing_extra_and_invalid_score_fields(): void
    {
        $this->seed('1');
        $payload = $this->controller()->search('x', 'tool_test')->toArray();
        $schema = json_decode(file_get_contents(dirname(__DIR__, 4) . '/packages/ai-vector/resources/semantic-search.schema.json'));
        unset($payload['meta']['scores']);
        self::assertFalse(new Validator()->validate(json_decode(json_encode($payload)), $schema)->isValid());
        $payload = ['results' => [['entity_type' => 'tool_test', 'id' => '1', 'score' => 2, 'metadata' => new \stdClass(), 'vector' => [1]]]];
        $schema = json_decode(file_get_contents(dirname(__DIR__, 4) . '/packages/ai-tools/resources/vector-search.schema.json'));
        self::assertFalse(new Validator()->validate(json_decode(json_encode($payload)), $schema)->isValid());
    }

    #[Test]
    public function lifecycle_and_both_refresh_paths_remove_stale_vectors_and_refuse_failed_counts(): void
    {
        $policy = EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => ['tool_test' => ['fields' => ['title'], 'allow_external' => true]]]]);
        $failing = new class implements EmbeddingProviderInterface {
            public function embed(string $text): array
            {
                throw new \RuntimeException('synthetic-provider-failure');
            }
        };
        foreach (['warm', 'warmBatch'] as $method) {
            $this->seed('01');
            $warmer = new SemanticIndexWarmer($this->manager, $this->storage, $failing, indexPolicy: $policy);
            try {
                $warmer->$method(['tool_test']);
                self::fail('Failed indexing must not return a successful report.');
            } catch (\RuntimeException $error) {
                self::assertSame('synthetic-provider-failure', $error->getMessage());
            }
            self::assertSame([], $this->storage->findSimilar([1.0, 0.0], 'tool_test', 10));
        }
        $this->seed('01');
        new EntityEmbeddingListener(storage: $this->storage, embeddingProvider: $failing, indexPolicy: $policy)->onPostSave(new EntityEvent($this->entities['01']));
        self::assertSame([], $this->storage->findSimilar([1.0, 0.0], 'tool_test', 10), 'post-commit failure is swallowed but old vector is removed');

        foreach (['warm', 'warmBatch'] as $method) {
            $this->seed('01');
            $this->queriedIds = ['01'];
            $this->entities = [];
            $report = new SemanticIndexWarmer($this->manager, $this->storage, $this->provider, indexPolicy: $policy)->$method(['tool_test']);
            self::assertSame(1, $report['missing_total']);
            self::assertSame(0, $report['stored_total']);
            self::assertSame([], $this->storage->findSimilar([1.0, 0.0], 'tool_test', 10));
        }
    }

    #[Test]
    public function optional_graph_payload_conforms_and_only_names_visible_results(): void
    {
        $this->seed('01');
        $this->seed('1');
        $relationships = $this->createStub(EntityRepositoryInterface::class);
        $query = $this->createStub(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('setAccount')->willReturnSelf();
        $query->method('execute')->willReturn(['edge']);
        $relationships->method('getQuery')->willReturn($query);
        $edge = new ToolTestEntity(['id' => 'edge', 'status' => 1, 'from_entity_type' => 'tool_test', 'from_entity_id' => '1', 'to_entity_type' => 'other', 'to_entity_id' => '2']);
        $relationships->method('findMany')->willReturn([$edge]);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getDefinition')->willReturn($this->manager->getDefinition('tool_test'));
        $manager->method('getRepository')->willReturnCallback(fn(string $type) => $type === 'relationship' ? $relationships : $this->repository);
        $manager->method('resolveFieldDefinitions')->willReturn([]);
        $document = new SearchController($manager, new ResourceSerializer($manager), $this->storage, $this->provider, $this->accessHandler('1'), $this->account)->search('x', 'tool_test')->toArray();
        $this->assertSchema($document, 'packages/ai-vector/resources/semantic-search.schema.json');
        self::assertSame(['01'], array_column($document['data'], 'id'));
        self::assertSame(['01'], array_keys((array) $document['meta']['score_breakdown']));
        self::assertSame(['01'], array_keys((array) $document['meta']['graph_context_counts']));
        self::assertSame(['01'], array_column($document['meta']['scores'], 'id'));
    }
}
