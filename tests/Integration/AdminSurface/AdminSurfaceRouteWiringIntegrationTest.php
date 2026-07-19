<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AdminSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\AdminSurfaceRoutePaths;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Workflow;

/**
 * Cross-package wiring integration test for the admin-surface seam.
 *
 * The audit (#846) flagged that there were no root-level integration
 * tests covering `AdminSurfaceServiceProvider`, `GenericAdminSurfaceHost`,
 * `/admin/_surface/session`, or `/admin/_surface/catalog`. The
 * package-local `AdminSurfaceServiceProviderTest` uses a fake host and
 * exercises only `registerRoutes(router, host)` — it does not exercise
 * the real production entry point `routes(router, entityTypeManager)`,
 * which constructs `GenericAdminSurfaceHost` itself, registers the
 * five admin-surface API routes, and adds the admin SPA catch-all.
 *
 * This test wires the real provider, real router, real entity type
 * manager, and real generic host together — the same composition the
 * kernel boots in production — and asserts:
 *
 * - All five `admin_surface.*` routes are registered with the canonical
 *   paths from `AdminSurfaceRoutePaths::PATH_*`.
 * - HTTP methods match the contract (GET for read endpoints, POST for
 *   actions).
 * - `WaaseyaaRouter::match()` resolves the canonical paths back to the
 *   correct route names with extracted parameters.
 * - `AdminSurfaceRoutePaths::generate()` is a round-trip inverse: every
 *   path it generates resolves back to the same route name.
 * - The admin SPA catch-all is registered with the `_surface`-excluding
 *   path requirement so API routes win the match race.
 *
 * Closes #846.
 */
#[CoversNothing]
final class AdminSurfaceRouteWiringIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelContext(
            projectRoot: sys_get_temp_dir(),
            config: [],
            manifestFormatters: [],
        );
        $provider->routes($this->router, $this->entityTypeManager);
    }

    #[Test]
    public function allFiveAdminSurfaceApiRoutesAreRegistered(): void
    {
        $routes = $this->router->getRouteCollection();

        foreach (
            [
                'admin_surface.session',
                'admin_surface.catalog',
                'admin_surface.list',
                'admin_surface.get',
                'admin_surface.action',
            ] as $name
        ) {
            self::assertNotNull(
                $routes->get($name),
                sprintf('AdminSurfaceServiceProvider::routes must register %s', $name),
            );
        }
    }

    #[Test]
    public function routePathsMatchAdminSurfaceRoutePathsConstants(): void
    {
        $routes = $this->router->getRouteCollection();

        self::assertSame(
            AdminSurfaceRoutePaths::PATH_SESSION,
            $routes->get('admin_surface.session')?->getPath(),
        );
        self::assertSame(
            AdminSurfaceRoutePaths::PATH_CATALOG,
            $routes->get('admin_surface.catalog')?->getPath(),
        );
        self::assertSame(
            AdminSurfaceRoutePaths::PATH_LIST,
            $routes->get('admin_surface.list')?->getPath(),
        );
        self::assertSame(
            AdminSurfaceRoutePaths::PATH_GET,
            $routes->get('admin_surface.get')?->getPath(),
        );
        self::assertSame(
            AdminSurfaceRoutePaths::PATH_ACTION,
            $routes->get('admin_surface.action')?->getPath(),
        );
    }

    #[Test]
    public function httpMethodsMatchContract(): void
    {
        $routes = $this->router->getRouteCollection();

        self::assertSame(['GET'], $routes->get('admin_surface.session')?->getMethods());
        self::assertSame(['GET'], $routes->get('admin_surface.catalog')?->getMethods());
        self::assertSame(['GET'], $routes->get('admin_surface.list')?->getMethods());
        self::assertSame(['GET'], $routes->get('admin_surface.get')?->getMethods());
        self::assertSame(['POST'], $routes->get('admin_surface.action')?->getMethods());
    }

    #[Test]
    public function sessionPathMatchesBackToSessionRoute(): void
    {
        $match = $this->router->match(AdminSurfaceRoutePaths::PATH_SESSION);

        self::assertSame('admin_surface.session', $match['_route']);
    }

    #[Test]
    public function catalogPathMatchesBackToCatalogRoute(): void
    {
        $match = $this->router->match(AdminSurfaceRoutePaths::PATH_CATALOG);

        self::assertSame('admin_surface.catalog', $match['_route']);
    }

    #[Test]
    public function listPathMatchesAndExtractsTypeParameter(): void
    {
        $match = $this->router->match('/admin/_surface/article');

        self::assertSame('admin_surface.list', $match['_route']);
        self::assertSame('article', $match['type']);
    }

    #[Test]
    public function getPathMatchesAndExtractsTypeAndIdParameters(): void
    {
        $match = $this->router->match('/admin/_surface/article/42');

        self::assertSame('admin_surface.get', $match['_route']);
        self::assertSame('article', $match['type']);
        self::assertSame('42', $match['id']);
    }

    #[Test]
    public function actionPathMatchesAndExtractsTypeAndActionParameters(): void
    {
        $postRouter = new WaaseyaaRouter(new RequestContext('', 'POST'));
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), [], []);
        $provider->routes($postRouter, $this->entityTypeManager);

        $match = $postRouter->match('/admin/_surface/article/action/publish');

        self::assertSame('admin_surface.action', $match['_route']);
        self::assertSame('article', $match['type']);
        self::assertSame('publish', $match['action']);
    }

    #[Test]
    public function generatedPathsRoundTripThroughTheRouter(): void
    {
        // RoutePaths::generate must produce paths that match back to the
        // same route name. This guards against drift where the generator
        // and the registered path patterns disagree.
        $cases = [
            ['admin_surface.session', [], 'admin_surface.session'],
            ['admin_surface.catalog', [], 'admin_surface.catalog'],
            ['admin_surface.list', ['type' => 'article'], 'admin_surface.list'],
            ['admin_surface.get', ['type' => 'article', 'id' => '42'], 'admin_surface.get'],
        ];

        foreach ($cases as [$generateName, $params, $expectedRoute]) {
            $path = AdminSurfaceRoutePaths::generate($generateName, $params);
            $match = $this->router->match($path);
            self::assertSame(
                $expectedRoute,
                $match['_route'],
                sprintf('generate(%s) must round-trip to %s', $generateName, $expectedRoute),
            );
        }
    }

    #[Test]
    public function adminSpaCatchAllExcludesSurfaceApiPaths(): void
    {
        $routes = $this->router->getRouteCollection();
        $spa = $routes->get('admin_spa');

        self::assertNotNull($spa, 'admin_spa catch-all must be registered alongside surface API routes');
        self::assertSame('/admin/{path}', $spa->getPath());

        // The path requirement must exclude `_surface` so admin_surface.*
        // routes win the match race against the SPA catch-all.
        $requirement = $spa->getRequirement('path');
        self::assertNotNull($requirement);
        self::assertStringContainsString('_surface', $requirement);
        self::assertStringContainsString('api', $requirement);
    }

    #[Test]
    public function surfacePathsBeatSpaCatchAllInMatchOrder(): void
    {
        // Ensures that even though the SPA catch-all matches `/admin/{path}`,
        // the more specific surface routes win for `/admin/_surface/...`.
        $sessionMatch = $this->router->match(AdminSurfaceRoutePaths::PATH_SESSION);
        self::assertSame('admin_surface.session', $sessionMatch['_route']);

        $catalogMatch = $this->router->match(AdminSurfaceRoutePaths::PATH_CATALOG);
        self::assertSame('admin_surface.catalog', $catalogMatch['_route']);

        // SPA catch-all matches non-_surface paths.
        $spaMatch = $this->router->match('/admin/dashboard');
        self::assertSame('admin_spa', $spaMatch['_route']);
        self::assertSame('dashboard', $spaMatch['path']);
    }

    #[Test]
    public function adminApiLookingPathsNeverMatchTheSpaHtmlShell(): void
    {
        $this->expectException(\Waaseyaa\Routing\Exception\RouteNotFoundException::class);

        $this->router->match('/admin/api/node/1/workflow/transitions');
    }

    #[Test]
    public function mountedSchemaActionDiscoversBundlesAndLoadsTheSelectedBundleFields(): void
    {
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fieldRegistry: $registry,
        );
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'workflow_state' => ['type' => 'string', 'label' => 'Workflow state'],
                'status' => ['type' => 'boolean', 'label' => 'Published'],
            ],
        ));
        $registry->registerBundleFields('article', 'page', [
            new FieldDefinition(
                name: 'page_body',
                type: 'text',
                targetEntityTypeId: 'article',
                targetBundle: 'page',
                label: 'Page body',
            ),
        ]);
        $registry->registerBundleFields('article', 'post', [
            new FieldDefinition(
                name: 'post_excerpt',
                type: 'string',
                targetEntityTypeId: 'article',
                targetBundle: 'post',
                label: 'Post excerpt',
            ),
        ]);

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('checkCreateAccess')->willReturn(AccessResult::allowed('integration fixture allows create'));
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral());
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getRawData')->willReturn(['article.page' => 'editorial']);
        $configFactory = $this->createStub(ConfigFactoryInterface::class);
        $configFactory->method('get')->willReturn($config);
        $workflowRepository = $this->createStub(EntityRepositoryInterface::class);
        $workflowRepository->method('find')->willReturn(new Workflow(['id' => 'editorial', 'label' => 'Editorial']));
        $bindingManager = $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class);
        $bindingManager->method('getDefinition')->willReturn(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: [...TestEntity::definitionKeys(), 'revision' => 'revision_id'],
            revisionable: true,
        ));
        $bindingManager->method('getRepository')->willReturn($workflowRepository);
        $bindingResolver = new WorkflowBindingResolver($configFactory, $bindingManager);
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), [], []);
        $provider->setKernelServices(new class ($registry, $accessHandler, $bindingResolver) implements KernelServicesInterface {
            public function __construct(
                private readonly FieldDefinitionRegistryInterface $registry,
                private readonly EntityAccessHandler $accessHandler,
                private readonly WorkflowBindingResolver $bindingResolver,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    FieldDefinitionRegistryInterface::class => $this->registry,
                    EntityAccessHandler::class => $this->accessHandler,
                    WorkflowBindingResolver::class => $this->bindingResolver,
                    default => null,
                };
            }
        });

        $router = new WaaseyaaRouter(new RequestContext('', 'POST'));
        $provider->routes($router, $manager);
        $controller = $router->getRouteCollection()->get('admin_surface.action')?->getDefault('_controller');
        self::assertIsCallable($controller);

        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);

        $baseRequest = Request::create('/admin/_surface/article/action/schema', 'POST', content: '{}');
        $baseRequest->attributes->set('_account', $account);
        $base = $controller($baseRequest, 'article', 'schema');

        self::assertTrue($base['ok']);
        self::assertSame('type', $base['data']['x-bundle-key']);
        self::assertSame(['page', 'post'], $base['data']['properties']['type']['enum']);
        self::assertSame('select', $base['data']['properties']['type']['x-widget']);

        $pageRequest = Request::create(
            '/admin/_surface/article/action/schema',
            'POST',
            content: json_encode(['bundle' => 'page'], JSON_THROW_ON_ERROR),
        );
        $pageRequest->attributes->set('_account', $account);
        $page = $controller($pageRequest, 'article', 'schema');

        self::assertTrue($page['ok']);
        self::assertArrayHasKey('page_body', $page['data']['properties']);
        self::assertArrayNotHasKey('post_excerpt', $page['data']['properties']);
        self::assertSame(['bound' => true, 'id' => 'editorial'], $page['data']['x-workflow']);
        self::assertArrayNotHasKey('workflow_state', $page['data']['properties']);
        self::assertArrayNotHasKey('status', $page['data']['properties']);

        $postRequest = Request::create(
            '/admin/_surface/article/action/schema',
            'POST',
            content: json_encode(['bundle' => 'post'], JSON_THROW_ON_ERROR),
        );
        $postRequest->attributes->set('_account', $account);
        $post = $controller($postRequest, 'article', 'schema');

        self::assertTrue($post['ok']);
        self::assertSame(['bound' => false, 'id' => null], $post['data']['x-workflow']);
        self::assertArrayHasKey('workflow_state', $post['data']['properties']);
        self::assertArrayHasKey('status', $post['data']['properties']);
    }

    /**
     * #2047 regression: exercise the production ProviderRegistry bus rather
     * than supplying a test-double KernelServicesInterface. The mounted admin
     * provider must receive the populated registry owned by the kernel manager.
     */
    #[Test]
    public function realProviderRegistrationMountsTheCanonicalPopulatedRegistry(): void
    {
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager($dispatcher, fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        foreach (['tribe_events', 'post', 'page', 'job_posting', 'announcement'] as $bundle) {
            $registry->registerBundleFields('node', $bundle, [
                new FieldDefinition(
                    name: $bundle . '_field',
                    type: 'string',
                    targetEntityTypeId: 'node',
                    targetBundle: $bundle,
                    label: $bundle . ' field',
                ),
            ]);
        }

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('checkCreateAccess')->willReturn(AccessResult::allowed('integration fixture allows create'));
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral());

        $providers = new ProviderRegistry(new NullLogger())->discoverAndRegister(
            manifest: new PackageManifest(providers: [AdminSurfaceServiceProvider::class]),
            projectRoot: sys_get_temp_dir(),
            config: [],
            entityTypeManager: $manager,
            database: \Waaseyaa\Database\DBALDatabase::createSqlite(),
            dispatcher: $dispatcher,
            accessHandlerAccessor: static fn(): EntityAccessHandler => $accessHandler,
        );
        self::assertCount(1, $providers);
        self::assertInstanceOf(AdminSurfaceServiceProvider::class, $providers[0]);

        $router = new WaaseyaaRouter(new RequestContext('', 'POST'));
        $providers[0]->routes($router, $manager);
        $controller = $router->getRouteCollection()->get('admin_surface.action')?->getDefault('_controller');
        self::assertIsCallable($controller);

        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);

        $request = Request::create('/admin/_surface/node/action/schema', 'POST', content: '{}');
        $request->attributes->set('_account', $account);
        $base = $controller($request, 'node', 'schema');

        self::assertTrue($base['ok']);
        self::assertSame('type', $base['data']['x-bundle-key']);
        self::assertSame(
            ['announcement', 'job_posting', 'page', 'post', 'tribe_events'],
            $base['data']['properties']['type']['enum'],
        );
    }
}
