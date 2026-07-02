<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipSchemaManager;

#[CoversClass(DiscoveryRouter::class)]
final class DiscoveryRouterTest extends TestCase
{
    private function createRouter(?EntityTypeManager $etm = null): DiscoveryRouter
    {
        $etm ??= new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();
        $handler = new DiscoveryApiHandler($etm, $db);

        return new DiscoveryRouter($handler, $etm);
    }

    #[Test]
    public function supports_discovery_topic_hub(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/topic-hub/node/1');
        $request->attributes->set('_controller', 'discovery.topic_hub');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_discovery_cluster(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/cluster/node/1');
        $request->attributes->set('_controller', 'discovery.cluster');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_api_discovery_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_passes_authenticated_account_from_request_to_discovery_controller(): void
    {
        $router = $this->createRouter($this->createManagerWithArticle());
        $request = $this->createDiscoveryRequest($this->createAccount(authenticated: true));

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('article', $payload['links']);
        self::assertSame('/api/article', $payload['links']['article']['href']);
    }

    #[Test]
    public function handle_passes_anonymous_account_from_request_to_discovery_controller(): void
    {
        $router = $this->createRouter($this->createManagerWithArticle());
        $request = $this->createDiscoveryRequest($this->createAccount(authenticated: false));

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['self' => '/api'], $payload['links']);
        self::assertStringNotContainsString('article', (string) $response->getContent());
    }

    #[Test]
    public function hub_clamps_anonymous_status_all_to_published_and_hides_unpublished_related_entity(): void
    {
        [$router, $ids] = $this->createRouterWithRelationshipFixtures();
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
            ['status' => 'all'],
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $relatedIds = array_map(
            static fn(array $item): string => (string) $item['related_entity_id'],
            $payload['data']['items'],
        );
        self::assertNotContains(
            (string) $ids['secret'],
            $relatedIds,
            'anonymous status=all must not leak the unpublished related entity identity (clamped to published)',
        );
        self::assertSame(0, $payload['data']['page']['total']);
    }

    #[Test]
    public function hub_clamps_anonymous_status_unpublished_to_published(): void
    {
        [$router, $ids] = $this->createRouterWithRelationshipFixtures();
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
            ['status' => 'unpublished'],
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // 'unpublished' clamped to 'published' means the (unpublished) related
        // node is filtered out just as it would be under an explicit
        // status=published request — not exposed via the unpublished spelling.
        self::assertSame(0, $payload['data']['page']['total']);
    }

    #[Test]
    public function hub_honors_status_all_for_authorized_administer_nodes_account(): void
    {
        [$router, $ids] = $this->createRouterWithRelationshipFixtures();
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: true, permissions: ['administer nodes']),
            ['status' => 'all'],
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $relatedIds = array_map(
            static fn(array $item): string => (string) $item['related_entity_id'],
            $payload['data']['items'],
        );
        self::assertContains(
            (string) $ids['secret'],
            $relatedIds,
            'authorized (administer nodes) caller must keep status=all discovery',
        );
        self::assertSame(1, $payload['data']['page']['total']);
    }

    #[Test]
    public function timeline_clamps_anonymous_status_all_to_published_and_hides_unpublished_related_entity(): void
    {
        [$router, $ids] = $this->createRouterWithRelationshipFixtures();
        $request = $this->createTimelineRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
            ['status' => 'all'],
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $relatedIds = array_map(
            static fn(array $item): string => (string) $item['related_entity_id'],
            $payload['data']['items'],
        );
        self::assertNotContains(
            (string) $ids['secret'],
            $relatedIds,
            'anonymous status=all must not leak the unpublished related entity identity via the timeline surface either',
        );
    }

    // --- Helpers ---

    /**
     * Builds a router wired to real entity-storage-backed 'node' and
     * 'relationship' entity types (mirrors
     * tests/Integration/Phase14/DiscoveryFixtureConsumersIntegrationTest.php),
     * seeded with a published source node, an UNPUBLISHED "secret" related
     * node, and a published relationship edge between them. This exercises
     * the real RelationshipTraversalService::browse() visibility gate rather
     * than a mock, so the WP2 status=all clamp is proven against production
     * behavior.
     *
     * @return array{0: DiscoveryRouter, 1: array{source: int|string, secret: int|string}}
     */
    private function createRouterWithRelationshipFixtures(): array
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $_id, EntityType $definition) use ($dispatcher, $resolver, $database): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';

                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $database,
                );
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string'],
                'status' => ['type' => 'boolean'],
            ],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
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
                'status' => ['type' => 'boolean'],
            ],
        ));
        $nodeRepository = $entityTypeManager->getRepository('node');
        $source = $nodeRepository->create(['title' => 'Source', 'type' => 'article', 'status' => 1]);
        $nodeRepository->save($source, validate: false);
        $secret = $nodeRepository->create(['title' => 'Secret Node', 'type' => 'article', 'status' => 0]);
        $nodeRepository->save($secret, validate: false);

        // getRepository('relationship') lazily creates the bare entity table
        // (id/uuid/_data) via the factory's ensureTable() call above; ensure()
        // must run AFTER that so it finds an existing table to extend with the
        // physical from_entity_type/to_entity_type/status/etc. columns that
        // RelationshipTraversalService queries with raw SQL (RelationshipSchemaManager
        // no-ops when the table does not exist yet — see its `ensure()` guard).
        // Relationship edge itself is UNPUBLISHED (status: 0). This lets the
        // same fixture exercise both spellings of the bypass: status=all
        // ignores the relationship's own status column entirely (so the edge
        // is found and its endpoint-visibility check is skipped too), while
        // status=unpublished matches this row by relationship status and
        // would — pre-fix — surface the secret node's identity because
        // unpublished-mode endpoint visibility keeps non-public endpoints.
        $relationshipRepository = $entityTypeManager->getRepository('relationship');
        new RelationshipSchemaManager($database)->ensure();
        $relationship = $relationshipRepository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $source->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $secret->id(),
            'status' => 0,
        ]);
        $relationshipRepository->save($relationship, validate: false);

        $handler = new DiscoveryApiHandler($entityTypeManager, $database);

        return [new DiscoveryRouter($handler, $entityTypeManager), ['source' => $source->id(), 'secret' => $secret->id()]];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function createTopicHubRequest(string $entityId, AccountInterface $account, array $query = []): Request
    {
        return $this->createDiscoveryActionRequest('discovery.topic_hub', $entityId, $account, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function createTimelineRequest(string $entityId, AccountInterface $account, array $query = []): Request
    {
        return $this->createDiscoveryActionRequest('discovery.timeline', $entityId, $account, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function createDiscoveryActionRequest(string $controller, string $entityId, AccountInterface $account, array $query): Request
    {
        $request = Request::create('/api/discovery/node/' . $entityId, 'GET', $query);
        $request->attributes->set('_controller', $controller);
        $request->attributes->set('entity_type', 'node');
        $request->attributes->set('id', $entityId);
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage(DBALDatabase::createSqlite()));

        return $request;
    }

    private function createManagerWithArticle(): EntityTypeManager
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $etm->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        return $etm;
    }

    private function createDiscoveryRequest(AccountInterface $account): Request
    {
        $request = Request::create('/api');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage(DBALDatabase::createSqlite()));

        return $request;
    }

    /**
     * @param list<string> $permissions
     */
    private function createAccount(bool $authenticated, array $permissions = []): AccountInterface
    {
        return new class($authenticated, $permissions) implements AccountInterface {
            /**
             * @param list<string> $permissions
             */
            public function __construct(
                private readonly bool $authenticated,
                private readonly array $permissions = [],
            ) {}

            public function id(): int|string
            {
                return $this->authenticated ? 1 : 0;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return [$this->authenticated ? 'authenticated' : 'anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }
}
