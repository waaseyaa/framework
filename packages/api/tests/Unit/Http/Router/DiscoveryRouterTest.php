<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
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

    // --- R7 WP2 (audit R5 residual #1): access-aware endpoint visibility ---

    #[Test]
    public function hub_hides_published_but_access_restricted_related_entity(): void
    {
        [$router, $ids] = $this->createRouterWithAccessRestrictedFixtures(restrictSecret: true);
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
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
            'a published-but-access-restricted related entity must not leak its identity through discovery',
        );
        self::assertSame(0, $payload['data']['page']['total']);
    }

    #[Test]
    public function hub_keeps_published_and_viewable_related_entity_with_access_handler_wired(): void
    {
        // Positive control: wiring the access handler must not over-drop a
        // legitimately viewable published related entity.
        [$router, $ids] = $this->createRouterWithAccessRestrictedFixtures(restrictSecret: false);
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
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
            'a viewable published related entity must still surface once access-awareness is wired',
        );
        self::assertSame(1, $payload['data']['page']['total']);
    }

    #[Test]
    public function hub_fails_closed_when_related_entity_is_unloadable_with_access_handler_wired(): void
    {
        [$router, $ids] = $this->createRouterWithAccessRestrictedFixtures(
            restrictSecret: false,
            dropSecretNode: true,
        );
        $request = $this->createTopicHubRequest(
            (string) $ids['source'],
            $this->createAccount(authenticated: false),
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $payload['data']['page']['total'], 'an unloadable related entity must fail closed, never disclosed');
    }

    // --- R8-c (audit R8 WP2): discovery source-entity view gate ---
    //
    // handleEndpoint already gated its OWN source entity (loadDiscoveryEntity
    // + isDiscoveryEntityPublic) before doing any work — see the tests above
    // this block are unaffected by that gate. handleTopicHub/handleCluster/
    // handleTimeline did NOT: a caller who cannot view the source entity
    // itself still got a 200 (existence-oracle / access-restriction oracle).
    // These tests prove the same gate now runs for all three, BEFORE the
    // cache read, and that a restricted-but-existing source is indistinguishable
    // from a truly-absent source (same status, same response body).

    #[Test]
    public function hub_returns_not_found_when_source_entity_is_access_restricted(): void
    {
        $this->assertSourceGateReturnsNotFoundAndIndistinguishableFromAbsent('discovery.topic_hub');
    }

    #[Test]
    public function cluster_returns_not_found_when_source_entity_is_access_restricted(): void
    {
        $this->assertSourceGateReturnsNotFoundAndIndistinguishableFromAbsent('discovery.cluster');
    }

    #[Test]
    public function timeline_returns_not_found_when_source_entity_is_access_restricted(): void
    {
        $this->assertSourceGateReturnsNotFoundAndIndistinguishableFromAbsent('discovery.timeline');
    }

    #[Test]
    public function hub_returns_200_with_data_when_source_entity_is_viewable(): void
    {
        [$router, $ids] = $this->createRouterWithSourceAccessFixture(forbidSource: false);
        $request = $this->createDiscoveryActionRequest('discovery.topic_hub', (string) $ids['source'], $this->createAccount(authenticated: false), []);

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $relatedIds = array_map(
            static fn(array $item): string => (string) $item['related_entity_id'],
            $payload['data']['items'],
        );
        self::assertContains(
            (string) $ids['related'],
            $relatedIds,
            'a viewable source must still serve hub data (the gate must not over-block)',
        );
    }

    #[Test]
    public function cluster_returns_200_with_data_when_source_entity_is_viewable(): void
    {
        [$router, $ids] = $this->createRouterWithSourceAccessFixture(forbidSource: false);
        $request = $this->createDiscoveryActionRequest('discovery.cluster', (string) $ids['source'], $this->createAccount(authenticated: false), []);

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            1,
            $payload['data']['page']['total'],
            'a viewable source must still serve cluster data (the gate must not over-block)',
        );
    }

    #[Test]
    public function timeline_returns_200_with_data_when_source_entity_is_viewable(): void
    {
        [$router, $ids] = $this->createRouterWithSourceAccessFixture(forbidSource: false);
        $request = $this->createDiscoveryActionRequest('discovery.timeline', (string) $ids['source'], $this->createAccount(authenticated: false), []);

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $relatedIds = array_map(
            static fn(array $item): string => (string) $item['related_entity_id'],
            $payload['data']['items'],
        );
        self::assertContains(
            (string) $ids['related'],
            $relatedIds,
            'a viewable source must still serve timeline data (the gate must not over-block)',
        );
    }

    /**
     * Shared assertion for the three cache-fronted discovery actions: a
     * restricted-but-existing source must 404 with the SAME status and SAME
     * response body a truly-absent source id would produce. Both requests
     * use the identical entity type + entity id string so any wording
     * difference in the 404 detail (which would leak "exists but denied" vs
     * "does not exist" to an attacker) is caught byte-for-byte.
     */
    private function assertSourceGateReturnsNotFoundAndIndistinguishableFromAbsent(string $controller): void
    {
        [$router, $ids] = $this->createRouterWithSourceAccessFixture(forbidSource: true);
        $sourceId = (string) $ids['source'];
        $account = $this->createAccount(authenticated: false);

        $restrictedRequest = $this->createDiscoveryActionRequest($controller, $sourceId, $account, []);
        $restrictedResponse = $router->handle($restrictedRequest);

        self::assertSame(404, $restrictedResponse->getStatusCode(), 'a source the caller cannot view must 404, not disclose hub/cluster/timeline data');

        $absentRouter = $this->createRouterWithEmptyNodeEntityType();
        $absentRequest = $this->createDiscoveryActionRequest($controller, $sourceId, $account, []);
        $absentResponse = $absentRouter->handle($absentRequest);

        self::assertSame(404, $absentResponse->getStatusCode(), 'a truly-absent source must also 404');
        self::assertSame(
            (string) $absentResponse->getContent(),
            (string) $restrictedResponse->getContent(),
            'a restricted (existing) source and a truly-absent source must be indistinguishable to the caller',
        );
        // Headers must also match — a differing Cache-Control/X-Waaseyaa-*
        // header would let an attacker distinguish absent from denied even
        // with identical bodies. (Already identical; pinned here.) The `date`
        // header is the current wall-clock time on each response and cannot
        // distinguish the two cases, so it is excluded to avoid a second-
        // boundary flake.
        $absentHeaders = $absentResponse->headers->all();
        $restrictedHeaders = $restrictedResponse->headers->all();
        unset($absentHeaders['date'], $restrictedHeaders['date']);
        self::assertSame(
            $absentHeaders,
            $restrictedHeaders,
            'response headers must not distinguish an absent source from a denied one',
        );
    }

    /**
     * Builds a router with a real EntityAccessHandler that forbids 'view' on
     * the SOURCE node itself (not a related entity — see
     * createRouterWithAccessRestrictedFixtures() above for the related-entity
     * variant covered by R7 WP2). A published, unrestricted related node is
     * linked via a published relationship edge so the positive-control tests
     * (forbidSource: false) can prove the gate does not over-block a
     * legitimately viewable source.
     *
     * @return array{0: DiscoveryRouter, 1: array{source: int|string, related: int|string}}
     */
    private function createRouterWithSourceAccessFixture(bool $forbidSource): array
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
        $related = $nodeRepository->create(['title' => 'Related', 'type' => 'article', 'status' => 1]);
        $nodeRepository->save($related, validate: false);

        $relationshipRepository = $entityTypeManager->getRepository('relationship');
        new RelationshipSchemaManager($database)->ensure();
        $relationship = $relationshipRepository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $source->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $related->id(),
            'status' => 1,
        ]);
        $relationshipRepository->save($relationship, validate: false);

        $accessHandler = new EntityAccessHandler([
            new DiscoveryForbidNodeAccessPolicy($forbidSource ? [(string) $source->id()] : []),
        ]);
        $handler = new DiscoveryApiHandler($entityTypeManager, $database, null, $accessHandler);

        return [new DiscoveryRouter($handler, $entityTypeManager), ['source' => $source->id(), 'related' => $related->id()]];
    }

    /**
     * A router wired to a 'node' entity type with real (empty) storage and no
     * access handler — used as the "truly absent" control for the source-gate
     * indistinguishability tests. loadDiscoveryEntity() returns null for any
     * id here, taking the identical `$resolvedEntity === null` branch a
     * forbidden-but-existing source takes via `!isDiscoveryEntityPublic()`.
     */
    private function createRouterWithEmptyNodeEntityType(): DiscoveryRouter
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

        $handler = new DiscoveryApiHandler($entityTypeManager, $database);

        return new DiscoveryRouter($handler, $entityTypeManager);
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
     * Builds a router wired to real entity-storage-backed 'node' and
     * 'relationship' entity types PLUS a real {@see EntityAccessHandler},
     * seeded with a PUBLISHED source node, a PUBLISHED "secret" related node,
     * and a PUBLISHED relationship edge between them.
     *
     * Unlike createRouterWithRelationshipFixtures() (which proves the
     * publish-STATUS gate via an unpublished secret node), this fixture
     * proves the R7 WP2 per-account ACCESS gate (audit R5 residual #1): the
     * secret node is fully PUBLISHED, so WorkflowVisibilityFilter alone would
     * disclose it — only an access-aware gate can withhold it.
     *
     * @return array{0: DiscoveryRouter, 1: array{source: int|string, secret: string}}
     */
    private function createRouterWithAccessRestrictedFixtures(bool $restrictSecret, bool $dropSecretNode = false): array
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

        if ($dropSecretNode) {
            // Never persisted — the relationship below points at an id nothing
            // can load, exercising the fail-closed "unloadable endpoint" path.
            $secretId = '999999';
        } else {
            $secret = $nodeRepository->create(['title' => 'Secret Node', 'type' => 'article', 'status' => 1]);
            $nodeRepository->save($secret, validate: false);
            $secretId = (string) $secret->id();
        }

        // getRepository('relationship') lazily creates the bare entity table
        // (id/uuid/_data) via the factory's ensureTable() call above; ensure()
        // must run AFTER that so it finds an existing table to extend with the
        // physical from_entity_type/to_entity_type/status/etc. columns that
        // RelationshipTraversalService queries with raw SQL.
        $relationshipRepository = $entityTypeManager->getRepository('relationship');
        new RelationshipSchemaManager($database)->ensure();
        $relationship = $relationshipRepository->create([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $source->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => $secretId,
            'status' => 1,
        ]);
        $relationshipRepository->save($relationship, validate: false);

        $accessHandler = new EntityAccessHandler([
            new DiscoveryForbidNodeAccessPolicy($restrictSecret ? [$secretId] : []),
        ]);
        $handler = new DiscoveryApiHandler($entityTypeManager, $database, null, $accessHandler);

        return [new DiscoveryRouter($handler, $entityTypeManager), ['source' => $source->id(), 'secret' => $secretId]];
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
            api: true,
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

/**
 * Allows 'view' on every 'node' entity except the ids listed in
 * $forbiddenIds — mirrors a real restrictive AccessPolicyInterface (e.g.
 * NodeAccessPolicy denying a private node) for the R7 WP2 discovery-path
 * access-awareness tests without depending on waaseyaa/node from this
 * package's tests.
 */
final class DiscoveryForbidNodeAccessPolicy implements AccessPolicyInterface
{
    /**
     * @param list<string> $forbiddenIds
     */
    public function __construct(private readonly array $forbiddenIds) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        if (in_array((string) $entity->id(), $this->forbiddenIds, true)) {
            return AccessResult::forbidden('Node is access-restricted for this test.');
        }

        return AccessResult::allowed('Node is viewable.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
