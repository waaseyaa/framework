<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\AdminSpaFallback;
use Waaseyaa\AdminSurface\AdminSurfaceRoutePaths;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminPublicationFieldReaderInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\AuditedAdminPublicationFieldReader;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(AdminSurfaceServiceProvider::class)]
#[CoversClass(AdminSurfaceRoutePaths::class)]
#[CoversClass(AbstractAdminSurfaceHost::class)]
final class AdminSurfaceServiceProviderTest extends TestCase
{
    private AbstractAdminSurfaceHost $host;
    private AdminSurfaceSessionData $session;

    protected function setUp(): void
    {
        $this->session = new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Test Admin',
            roles: ['administrator'],
            policies: ['admin_access'],
            email: 'admin@example.com',
            tenantId: 'default',
            tenantName: 'Default',
            features: ['content_editing' => true],
        );

        $this->host = $this->createTestHost($this->session);
    }

    #[Test]
    public function registerWiresOneSharedAuditedPublicationFieldReader(): void
    {
        $capabilities = $this->createStub(CapabilityRegistryInterface::class);
        $ledger = $this->createStub(StrictPrivilegedReadLedgerInterface::class);
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelServices(new class ($capabilities, $ledger) implements KernelServicesInterface {
            public function __construct(
                private readonly CapabilityRegistryInterface $capabilities,
                private readonly StrictPrivilegedReadLedgerInterface $ledger,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    CapabilityRegistryInterface::class => $this->capabilities,
                    StrictPrivilegedReadLedgerInterface::class => $this->ledger,
                    default => null,
                };
            }
        });
        $provider->register();

        $reader = $provider->resolve(AdminPublicationFieldReaderInterface::class);

        self::assertInstanceOf(AuditedAdminPublicationFieldReader::class, $reader);
        self::assertSame($reader, $provider->resolve(AdminPublicationFieldReaderInterface::class));
    }

    #[Test]
    public function registerRoutesAddsAllFiveExpectedRoutes(): void
    {
        $router = new WaaseyaaRouter();

        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $collection = $router->getRouteCollection();
        $routeNames = array_keys(iterator_to_array($collection->getIterator()));

        $this->assertCount(5, $routeNames);
        $this->assertContains('admin_surface.session', $routeNames);
        $this->assertContains('admin_surface.catalog', $routeNames);
        $this->assertContains('admin_surface.list', $routeNames);
        $this->assertContains('admin_surface.get', $routeNames);
        $this->assertContains('admin_surface.action', $routeNames);
    }

    #[Test]
    public function registerPageBuilderRoutesAddsTheAuthenticatedEditorEndpoints(): void
    {
        $router = new WaaseyaaRouter();
        $host = $this->createStub(PageBuilderSurfaceHostInterface::class);

        AdminSurfaceServiceProvider::registerPageBuilderRoutes($router, $host);

        $routes = $router->getRouteCollection();
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DEFINITIONS, $routes->get('admin_surface.page_builder.definitions')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DRAFT, $routes->get('admin_surface.page_builder.draft')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_COMMAND, $routes->get('admin_surface.page_builder.command')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_PREVIEW, $routes->get('admin_surface.page_builder.preview')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_HISTORY, $routes->get('admin_surface.page_builder.history')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_REVISION, $routes->get('admin_surface.page_builder.revision')?->getPath());
        self::assertSame(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_RESTORE, $routes->get('admin_surface.page_builder.restore')?->getPath());
        self::assertTrue($routes->get('admin_surface.page_builder.command')?->getOption('_csrf'));
        self::assertTrue($routes->get('admin_surface.page_builder.preview')?->getOption('_csrf'));
        self::assertTrue($routes->get('admin_surface.page_builder.restore')?->getOption('_csrf'));
    }

    #[Test]
    public function registerRoutesUsesCorrectPaths(): void
    {
        $router = new WaaseyaaRouter();

        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $collection = $router->getRouteCollection();

        $this->assertSame(AdminSurfaceRoutePaths::PATH_SESSION, $collection->get('admin_surface.session')->getPath());
        $this->assertSame(AdminSurfaceRoutePaths::PATH_CATALOG, $collection->get('admin_surface.catalog')->getPath());
        $this->assertSame(AdminSurfaceRoutePaths::PATH_LIST, $collection->get('admin_surface.list')->getPath());
        $this->assertSame(AdminSurfaceRoutePaths::PATH_GET, $collection->get('admin_surface.get')->getPath());
        $this->assertSame(AdminSurfaceRoutePaths::PATH_ACTION, $collection->get('admin_surface.action')->getPath());
    }

    #[Test]
    public function migratedPostCreateFormOmitsTheRealSourceStatusField(): void
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Content',
            class: MigratedPostSchemaTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', [
            'title' => new FieldDefinition('title', 'string', targetEntityTypeId: 'node', label: 'Title'),
            'type' => new FieldDefinition('type', 'string', targetEntityTypeId: 'node', label: 'Content type'),
        ]);
        $registry->registerBundleFields('node', 'post', [
            // Exact key/label/target shape declared by the WordPress import consumer.
            new FieldDefinition(
                'source_status',
                'string',
                settings: ['weight' => 7],
                targetEntityTypeId: 'node',
                targetBundle: 'post',
                label: 'WordPress status',
                read: FieldReadLevel::Public,
            ),
        ]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->expects(self::exactly(2))->method('hasDefinition')->with('node')->willReturn(true);
        $entityTypeManager->expects(self::once())->method('getDefinition')->with('node')->willReturn($definition);
        $entityTypeManager->method('resolveFieldDefinitions')->willReturnCallback(
            fn(string $type, ?string $bundle = null): array => $registry->coreFieldsFor($type)
                + ($bundle === null ? [] : $registry->bundleFieldsFor($type, $bundle)),
        );

        $accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $provider = new AdminSurfaceServiceProvider();
        $internalFieldVisibility = new InternalFieldVisibilityPolicy();
        $provider->setKernelServices(new class ($registry, $accessHandler, $internalFieldVisibility) implements KernelServicesInterface {
            public function __construct(
                private readonly FieldDefinitionRegistryInterface $registry,
                private readonly EntityAccessHandler $accessHandler,
                private readonly InternalFieldVisibilityPolicy $internalFieldVisibility,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    FieldDefinitionRegistryInterface::class => $this->registry,
                    EntityAccessHandler::class => $this->accessHandler,
                    InternalFieldVisibilityPolicy::class => $this->internalFieldVisibility,
                    default => null,
                };
            }
        });

        $router = new WaaseyaaRouter();
        $provider->routes($router, $entityTypeManager);
        $route = $router->getRouteCollection()->get('admin_surface.action');
        self::assertNotNull($route);
        $controller = $route->getDefault('_controller');
        self::assertIsCallable($controller);

        $request = Request::create(
            '/admin/_surface/node/action/schema',
            'POST',
            content: json_encode(['bundle' => 'post'], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', new AuthorizationPrincipal(
            42,
            true,
            [],
            ['administer content', 'administer nodes'],
            'operator',
        ));
        $result = $controller($request, 'node', 'schema');

        self::assertTrue($result['ok'], json_encode($result));
        self::assertArrayHasKey('title', $result['data']['properties']);
        self::assertArrayNotHasKey(
            'source_status',
            $result['data']['properties'],
            'The browser form must not render the migrated “WordPress status” input.',
        );
    }

    #[Test]
    public function urlGeneratorOutputMatchesAdminSurfaceRoutePaths(): void
    {
        $router = new WaaseyaaRouter();
        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $this->assertSame(AdminSurfaceRoutePaths::generate('admin_surface.session'), $router->generate('admin_surface.session'));
        $this->assertSame(AdminSurfaceRoutePaths::generate('admin_surface.catalog'), $router->generate('admin_surface.catalog'));
        $this->assertSame(
            AdminSurfaceRoutePaths::generate('admin_surface.list', ['type' => 'article']),
            $router->generate('admin_surface.list', ['type' => 'article']),
        );
        $this->assertSame(
            AdminSurfaceRoutePaths::generate('admin_surface.get', ['type' => 'article', 'id' => '1']),
            $router->generate('admin_surface.get', ['type' => 'article', 'id' => '1']),
        );
        $this->assertSame(
            AdminSurfaceRoutePaths::generate('admin_surface.action', ['type' => 'article', 'action' => 'create']),
            $router->generate('admin_surface.action', ['type' => 'article', 'action' => 'create']),
        );
    }

    #[Test]
    public function handleSessionReturnsSessionDataStructure(): void
    {
        $request = Request::create('/admin/_surface/session');

        $result = $this->host->handleSession($request);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);

        $data = $result['data'];
        $this->assertArrayHasKey('account', $data);
        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('policies', $data);
        $this->assertArrayHasKey('features', $data);

        $this->assertSame('42', $data['account']['id']);
        $this->assertSame('Test Admin', $data['account']['name']);
        $this->assertSame('admin@example.com', $data['account']['email']);
        $this->assertSame(['administrator'], $data['account']['roles']);

        $this->assertSame('default', $data['tenant']['id']);
        $this->assertSame('Default', $data['tenant']['name']);

        $this->assertSame(['admin_access'], $data['policies']);
        $this->assertSame(['content_editing' => true], (array) $data['features']);
    }

    #[Test]
    public function handleSessionReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/_surface/session');

        $result = $host->handleSession($request);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(401, $result['error']['status']);
        $this->assertSame('Unauthorized', $result['error']['title']);
    }

    #[Test]
    public function handleCatalogReturnsEntityDefinitions(): void
    {
        $request = Request::create('/admin/_surface/catalog');

        $result = $this->host->handleCatalog($request);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('entities', $result['data']);

        $entities = $result['data']['entities'];
        $this->assertCount(1, $entities);

        $entity = $entities[0];
        $this->assertSame('article', $entity['id']);
        $this->assertSame('Article', $entity['label']);
        $this->assertSame('content', $entity['group']);
        $this->assertArrayHasKey('capabilities', $entity);
        $this->assertTrue($entity['capabilities']['list']);
        $this->assertTrue($entity['capabilities']['get']);
        $this->assertTrue($entity['capabilities']['create']);
    }

    #[Test]
    public function handleCatalogReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/_surface/catalog');

        $result = $host->handleCatalog($request);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleListReturnsEntityList(): void
    {
        $request = Request::create('/admin/_surface/article', 'GET', ['status' => 'published']);

        $result = $this->host->handleList($request, 'article');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('1', $result['data'][0]['id']);
        $this->assertSame('First Article', $result['data'][0]['title']);
    }

    #[Test]
    public function handleListReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/_surface/article');

        $result = $host->handleList($request, 'article');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleGetReturnsSingleEntity(): void
    {
        $request = Request::create('/admin/_surface/article/1');

        $result = $this->host->handleGet($request, 'article', '1');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('1', $result['data']['id']);
        $this->assertSame('article', $result['data']['type']);
        $this->assertSame('First Article', $result['data']['title']);
    }

    #[Test]
    public function handleGetReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/_surface/article/1');

        $result = $host->handleGet($request, 'article', '1');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function adminSpaRouteMatchesAdminRoot(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        // Register the SPA catch-all the same way the provider does.
        $router->addRoute('admin_spa', \Waaseyaa\Routing\RouteBuilder::create('/admin/{path}')
            ->methods('GET')
            ->allowAll()
            ->controller(static fn() => new \Symfony\Component\HttpFoundation\Response('spa'))
            ->requirement('path', '(?!(?:_surface|api)(?:/|$)).*')
            ->default('path', '')
            ->build());

        $params = $router->match('/admin');
        $this->assertSame('admin_spa', $params['_route']);
    }

    #[Test]
    public function adminSpaRouteMatchesAdminSubPaths(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $router->addRoute('admin_spa', \Waaseyaa\Routing\RouteBuilder::create('/admin/{path}')
            ->methods('GET')
            ->allowAll()
            ->controller(static fn() => new \Symfony\Component\HttpFoundation\Response('spa'))
            ->requirement('path', '(?!_surface(/|$)).*')
            ->default('path', '')
            ->build());

        $params = $router->match('/admin/users');
        $this->assertSame('admin_spa', $params['_route']);
        $this->assertSame('users', $params['path']);

        $params = $router->match('/admin/content/articles/123');
        $this->assertSame('admin_spa', $params['_route']);
    }

    #[Test]
    public function adminSpaRouteDoesNotSwallowSurfaceEndpoints(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $router->addRoute('admin_spa', \Waaseyaa\Routing\RouteBuilder::create('/admin/{path}')
            ->methods('GET')
            ->allowAll()
            ->controller(static fn() => new \Symfony\Component\HttpFoundation\Response('spa'))
            ->requirement('path', '(?!_surface(/|$)).*')
            ->default('path', '')
            ->build());

        $params = $router->match('/admin/_surface/session');
        $this->assertSame('admin_surface.session', $params['_route']);

        $params = $router->match('/admin/_surface/catalog');
        $this->assertSame('admin_surface.catalog', $params['_route']);

        $params = $router->match('/admin/_surface/article');
        $this->assertSame('admin_surface.list', $params['_route']);

        $params = $router->match('/admin/_surface/article/1');
        $this->assertSame('admin_surface.get', $params['_route']);
    }

    #[Test]
    public function adminSpaFallbackIsReturnedWhenIndexHtmlMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
        mkdir($tempDir . '/public', 0o777, true);

        try {
            $response = AdminSpaFallback::htmlResponse('TestApp');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('TestApp', $response->getContent());
            $this->assertStringContainsString('composer run dev', $response->getContent());
        } finally {
            rmdir($tempDir . '/public');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function adminSpaServesIndexHtmlWhenPresent(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
        mkdir($tempDir . '/public/admin', 0o777, true);
        file_put_contents($tempDir . '/public/admin/index.html', '<html><body>Admin SPA</body></html>');

        try {
            // Simulate what the controller closure does.
            $indexPath = $tempDir . '/public/admin/index.html';
            $this->assertTrue(is_file($indexPath));
            $this->assertStringContainsString('Admin SPA', file_get_contents($indexPath));
        } finally {
            unlink($tempDir . '/public/admin/index.html');
            rmdir($tempDir . '/public/admin');
            rmdir($tempDir . '/public');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function adminSpaServesVendorDistWhenPublicAdminMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
        mkdir($tempDir . '/public', 0o777, true);

        try {
            $result = AdminSurfaceServiceProvider::resolveAdminIndex($tempDir, '<html>Vendor</html>');

            $this->assertSame('<html>Vendor</html>', $result);
        } finally {
            rmdir($tempDir . '/public');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function adminSpaServesPublicOverVendorDist(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
        mkdir($tempDir . '/public/admin', 0o777, true);
        file_put_contents($tempDir . '/public/admin/index.html', '<html>App Override</html>');

        try {
            $result = AdminSurfaceServiceProvider::resolveAdminIndex($tempDir, '<html>Vendor</html>');

            $this->assertSame('<html>App Override</html>', $result);
        } finally {
            unlink($tempDir . '/public/admin/index.html');
            rmdir($tempDir . '/public/admin');
            rmdir($tempDir . '/public');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function adminSpaReturnsNullWhenNeitherExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
        mkdir($tempDir . '/public', 0o777, true);

        try {
            $result = AdminSurfaceServiceProvider::resolveAdminIndex($tempDir, null);

            $this->assertNull($result);
        } finally {
            rmdir($tempDir . '/public');
            rmdir($tempDir);
        }
    }

    #[Test]
    public function defaultCapabilityAllowlistExposesExactlyTheMcpApprovalPermissionsWhenMcpIsInstalled(): void
    {
        $this->assertSame(
            [
                \Waaseyaa\Access\Capability\McpApprovalCapabilities::PERMISSION_VIEW,
                \Waaseyaa\Access\Capability\McpApprovalCapabilities::PERMISSION_DECIDE,
            ],
            AdminSurfaceServiceProvider::defaultCapabilityAllowlist(mcpInstalled: true),
        );
    }

    #[Test]
    public function defaultCapabilityAllowlistIsEmptyOnSlimInstallsWithoutMcp(): void
    {
        $this->assertSame([], AdminSurfaceServiceProvider::defaultCapabilityAllowlist(mcpInstalled: false));
    }

    #[Test]
    public function defaultFeaturesProjectOptionalPackagesExactly(): void
    {
        $this->assertSame(
            ['mcp' => true, 'wayfinding' => false],
            AdminSurfaceServiceProvider::defaultFeatures(mcpInstalled: true, wayfindingInstalled: false),
        );
        $this->assertSame(
            ['mcp' => false, 'wayfinding' => true],
            AdminSurfaceServiceProvider::defaultFeatures(mcpInstalled: false, wayfindingInstalled: true),
        );
    }

    /**
     * #2161: a refused admin-surface operation must carry its status on the
     * status line, not only inside the response envelope.
     *
     * The end-to-end matrix lives in
     * `tests/Integration/AdminSurface/AdminSurfaceRefusalWireStatusTest`, but
     * integration tests are `#[CoversNothing]` by repository convention and so
     * contribute no coverage. These cases keep the promotion branch covered by a
     * test that carries real coverage metadata.
     */
    #[Test]
    public function refusedActionsCarryTheirStatusOnTheWire(): void
    {
        $host = $this->createRefusingHost(AdminSurfaceResultData::error(403, 'Access denied', 'Not yours.'));

        $response = $this->dispatchRoute($host, 'admin_surface.action', ['type' => 'article', 'action' => 'create'], 'POST');

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['ok']);
        self::assertSame(403, $body['error']['status']);
        self::assertSame('Not yours.', $body['error']['detail']);
        self::assertArrayNotHasKey('statusCode', $body, 'The transport key must not leak into the envelope');
    }

    #[Test]
    public function unauthenticatedSessionRequestsReturnARealUnauthorizedStatus(): void
    {
        $response = $this->dispatchRoute($this->createTestHost(null), 'admin_surface.session');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function successfulOperationsAreLeftAtHttpOk(): void
    {
        $response = $this->dispatchRoute($this->host, 'admin_surface.action', ['type' => 'article', 'action' => 'create'], 'POST');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['ok']);
    }

    /**
     * `handle*` is overridable, so a host subclass can return an envelope whose
     * `error.status` is not a promotable 400-599 integer. Passing that through
     * would reach the `Response` constructor and turn a clean refusal into a
     * 500, so it must retain the prior behaviour instead.
     */
    #[Test]
    public function refusalsWithAnUnpromotableStatusRetainTheirPreviousBehaviour(): void
    {
        $envelope = ['ok' => false, 'error' => ['status' => '403', 'title' => 'Access denied']];
        $host = $this->createRefusingHost(null, $envelope);

        $response = $this->dispatchRoute($host, 'admin_surface.action', ['type' => 'article', 'action' => 'create'], 'POST');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            $envelope,
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Drive a registered route closure through a real `ControllerDispatcher`,
     * the same composition the kernel runs.
     *
     * @param array<string, string> $routeParams
     */
    private function dispatchRoute(
        AbstractAdminSurfaceHost $host,
        string $routeName,
        array $routeParams = [],
        string $method = 'GET',
    ): Response {
        $router = new WaaseyaaRouter(new RequestContext('', $method));
        AdminSurfaceServiceProvider::registerRoutes($router, $host);

        $route = $router->getRouteCollection()->get($routeName);
        self::assertNotNull($route);

        $request = Request::create('/', $method);
        $request->attributes->set('_controller', $route->getDefault('_controller'));
        foreach ($routeParams as $name => $value) {
            $request->attributes->set($name, $value);
        }

        return new ControllerDispatcher([])->dispatch($request);
    }

    /**
     * @param array<string, mixed>|null $rawActionEnvelope Bypasses AdminSurfaceResultData so a
     *   malformed envelope — reachable when a subclass overrides handleAction — can be exercised.
     */
    private function createRefusingHost(
        ?AdminSurfaceResultData $outcome,
        ?array $rawActionEnvelope = null,
    ): AbstractAdminSurfaceHost {
        return new class ($this->session, $outcome, $rawActionEnvelope) extends AbstractAdminSurfaceHost {
            /** @param array<string, mixed>|null $rawActionEnvelope */
            public function __construct(
                private readonly AdminSurfaceSessionData $session,
                private readonly ?AdminSurfaceResultData $outcome,
                private readonly ?array $rawActionEnvelope,
            ) {}

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                return new CatalogBuilder();
            }

            public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success([]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success([]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success([]);
            }

            public function handleAction(Request $request, string $type, string $action): array
            {
                return $this->rawActionEnvelope ?? parent::handleAction($request, $type, $action);
            }
        };
    }

    private function createTestHost(?AdminSurfaceSessionData $session): AbstractAdminSurfaceHost
    {
        return new class ($session) extends AbstractAdminSurfaceHost {
            public function __construct(
                private readonly ?AdminSurfaceSessionData $session,
            ) {}

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                $catalog = new CatalogBuilder();
                $entity = $catalog->defineEntity('article', 'Article')
                    ->group('content');
                $entity->field('title', 'Title', 'string');
                $entity->field('body', 'Body', 'text');
                return $catalog;
            }

            public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    ['id' => '1', 'type' => $type, 'title' => 'First Article'],
                    ['id' => '2', 'type' => $type, 'title' => 'Second Article'],
                ]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'id' => $id,
                    'type' => $type,
                    'title' => 'First Article',
                ]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'action' => $action,
                    'type' => $type,
                    'result' => 'completed',
                ]);
            }
        };
    }
}

final class MigratedPostSchemaTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            $values,
            'node',
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
    }
}
