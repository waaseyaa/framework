<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\CorsHandler;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\SSR\LanguageResolver;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EventListenerRegistrar;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(HttpKernel::class)]
final class HttpKernelTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        putenv('WAASEYAA_APP_SECRET');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_http_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);
        mkdir($this->projectRoot . '/vendor/composer', 0755, true);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing', 'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Test']];");
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );
    }

    protected function tearDown(): void
    {
        putenv('WAASEYAA_APP_SECRET');
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function is_an_abstract_kernel(): void
    {
        $this->assertTrue(is_subclass_of(HttpKernel::class, AbstractKernel::class));
    }

    #[Test]
    public function handle_returns_http_response(): void
    {
        $ref = new \ReflectionMethod(HttpKernel::class, 'handle');

        $this->assertSame('Symfony\Component\HttpFoundation\Response', $ref->getReturnType()?->getName());
    }

    #[Test]
    public function provides_project_root(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $this->assertSame('/tmp/test-project', $kernel->getProjectRoot());
    }

    #[Test]
    public function resolve_cors_headers_for_allowed_origin(): void
    {
        $handler = new CorsHandler(allowedOrigins: ['http://localhost:3000']);

        $headers = $handler->resolveCorsHeaders('http://localhost:3000');

        $this->assertCount(5, $headers);
        $this->assertContains('Access-Control-Allow-Origin: http://localhost:3000', $headers);
        $this->assertContains('Vary: Origin', $headers);
    }

    #[Test]
    public function resolve_cors_headers_for_disallowed_origin_returns_empty_list(): void
    {
        $handler = new CorsHandler(allowedOrigins: ['http://localhost:3000']);

        $headers = $handler->resolveCorsHeaders('http://evil.test');

        $this->assertSame([], $headers);
    }

    #[Test]
    public function resolve_cors_headers_allows_localhost_any_port_in_development_mode(): void
    {
        $handler = new CorsHandler(allowedOrigins: ['http://localhost:3000'], allowDevLocalhostPorts: true);

        $headers = $handler->resolveCorsHeaders('http://localhost:4321');
        $this->assertContains('Access-Control-Allow-Origin: http://localhost:4321', $headers);

        $headersLoopback = $handler->resolveCorsHeaders('http://127.0.0.1:5173');
        $this->assertContains('Access-Control-Allow-Origin: http://127.0.0.1:5173', $headersLoopback);
    }

    #[Test]
    public function resolve_cors_headers_does_not_allow_non_localhost_in_development_mode(): void
    {
        $handler = new CorsHandler(allowedOrigins: ['http://localhost:3000'], allowDevLocalhostPorts: true);

        $headers = $handler->resolveCorsHeaders('http://example.com:3001');
        $this->assertSame([], $headers);
    }

    #[Test]
    public function detects_cors_preflight_request_method(): void
    {
        $handler = new CorsHandler();

        $this->assertTrue($handler->isCorsPreflightRequest('OPTIONS'));
        $this->assertTrue($handler->isCorsPreflightRequest('options'));
        $this->assertFalse($handler->isCorsPreflightRequest('GET'));
    }

    #[Test]
    public function detects_development_mode_from_common_environment_names(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');

        $method = new \ReflectionMethod(HttpKernel::class, 'isDevelopmentMode');

        $configProp->setValue($kernel, ['environment' => 'development']);
        $this->assertTrue($method->invoke($kernel));

        $configProp->setValue($kernel, ['environment' => 'local']);
        $this->assertTrue($method->invoke($kernel));

        $configProp->setValue($kernel, ['environment' => 'production']);
        $this->assertFalse($method->invoke($kernel));
    }

    #[Test]
    public function dev_fallback_account_is_disabled_by_default_even_in_development(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setValue($kernel, ['environment' => 'development']);

        $method = new \ReflectionMethod(HttpKernel::class, 'shouldUseDevFallbackAccount');

        $this->assertFalse($method->invoke($kernel, 'cli-server'));
    }

    #[Test]
    public function dev_fallback_account_requires_development_mode_flag_and_cli_server_sapi(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');

        $method = new \ReflectionMethod(HttpKernel::class, 'shouldUseDevFallbackAccount');

        $configProp->setValue($kernel, [
            'environment' => 'development',
            'auth' => ['dev_fallback_account' => true],
        ]);
        $this->assertTrue($method->invoke($kernel, 'cli-server'));

        $configProp->setValue($kernel, [
            'environment' => 'production',
            'auth' => ['dev_fallback_account' => true],
        ]);
        $this->assertFalse($method->invoke($kernel, 'cli-server'));

        $configProp->setValue($kernel, [
            'environment' => 'development',
            'auth' => ['dev_fallback_account' => true],
        ]);
        $this->assertFalse($method->invoke($kernel, 'cli'));
    }

    #[Test]
    public function dev_fallback_account_is_allowed_under_frankenphp_sapi_in_development_with_flag(): void
    {
        // FrankenPHP is the production runtime too, so the SAPI alone must not
        // unlock the dev fallback: development mode AND the explicit opt-in flag
        // are still required.
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $method = new \ReflectionMethod(HttpKernel::class, 'shouldUseDevFallbackAccount');

        // dev + flag => enabled under FrankenPHP.
        $configProp->setValue($kernel, [
            'environment' => 'local',
            'auth' => ['dev_fallback_account' => true],
        ]);
        $this->assertTrue($method->invoke($kernel, 'frankenphp'));

        // production + flag => still locked (isDevelopmentMode() is false).
        $configProp->setValue($kernel, [
            'environment' => 'production',
            'auth' => ['dev_fallback_account' => true],
        ]);
        $this->assertFalse($method->invoke($kernel, 'frankenphp'));

        // dev, no flag => locked.
        $configProp->setValue($kernel, [
            'environment' => 'local',
        ]);
        $this->assertFalse($method->invoke($kernel, 'frankenphp'));
    }

    #[Test]
    public function registers_core_routes_on_router(): void
    {
        $kernel = new HttpKernel($this->projectRoot);

        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
        $boot->invoke($kernel);

        $etmProp = new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager');
        $entityTypeManager = $etmProp->getValue($kernel);

        $registrar = new BuiltinRouteRegistrar($entityTypeManager);
        $router = new \Waaseyaa\Routing\WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        $registrar->register($router);

        $routes = $router->getRouteCollection();
        // api.schema.show is now owned by ApiServiceProvider::routes() (WP5 inversion)
        // and is absent from the bare registrar. Coverage is in ApiServiceProviderAdminRoutesTest.
        $this->assertNull($routes->get('api.schema.show'), 'api.schema.show is provider-owned since WP5 inversion.');
        $this->assertNotNull($routes->get('api.openapi'));
        $this->assertNotNull($routes->get('api.entity_types'));
        $this->assertNotNull($routes->get('api.broadcast'));
        $this->assertNotNull($routes->get('api.search'));
        $this->assertNotNull($routes->get('api.discovery.hub'));
        $this->assertNotNull($routes->get('api.discovery.cluster'));
        $this->assertNotNull($routes->get('api.discovery.timeline'));
        $this->assertNotNull($routes->get('api.discovery.endpoint'));
        $this->assertNotNull($routes->get('api.media.upload'));
        $this->assertNull($routes->get('mcp.endpoint'), 'MCP route is registered by McpServiceProvider, not BuiltinRouteRegistrar.');
        $this->assertNotNull($routes->get('public.home'));
        $this->assertNotNull($routes->get('public.page'));
        $this->assertTrue((bool) $routes->get('public.home')?->getOption('_render'));
    }

    #[Test]
    public function kernel_cache_bins_use_the_derived_cache_key_without_persisting_key_material(): void
    {
        $master = str_repeat('c', 32);
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode($master));
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing', 'cache' => ['hmac_key' => 'legacy-key-must-be-ignored']];",
        );
        $kernel = new HttpKernel($this->projectRoot);
        (new \ReflectionMethod(AbstractKernel::class, 'boot'))->invoke($kernel);

        $cacheProperty = new \ReflectionProperty(HttpKernel::class, 'discoveryCache');
        $cache = $cacheProperty->getValue($kernel);
        self::assertInstanceOf(CacheBackendInterface::class, $cache);
        $cache->set('secret-custody-probe', 'value');

        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $stored = (string) $database->getConnection()->fetchOne(
            "SELECT data FROM cache_discovery WHERE cid = 'secret-custody-probe'",
        );
        $derived = hash_hkdf(
            'sha256',
            $master,
            32,
            ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
            ApplicationSecret::HKDF_SALT,
        );

        self::assertTrue(
            str_starts_with($stored, hash_hmac('sha256', serialize('value'), $derived)),
            'Kernel cache payloads must carry the derived-key HMAC.',
        );
        self::assertFalse(str_contains($stored, $master), 'Cache rows must not contain master bytes.');
        self::assertFalse(str_contains($stored, $derived), 'Cache rows must not contain derived bytes.');
        self::assertFalse(
            str_contains($stored, base64_encode($master)),
            'Cache rows must not contain encoded master bytes.',
        );
    }

    #[Test]
    public function matchedEntityParametersAreUpcastBeforeControllerDispatch(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with('42')->willReturn($entity);
        $entityTypeManager = $this->createMock(EntityTypeManager::class);
        $entityTypeManager->method('getRepository')->with('test')->willReturn($repository);

        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
            {
                $router->addRoute('test.entity', RouteBuilder::create('/test/{id}')
                    ->controller(static fn(): array => [])
                    ->entityParameter('id', 'test')
                    ->methods('GET')
                    ->allowAll()
                    ->build());
            }
        };

        $kernel = new HttpKernel($this->projectRoot);
        (new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager'))->setValue($kernel, $entityTypeManager);
        (new \ReflectionProperty(AbstractKernel::class, 'providers'))->setValue($kernel, [$provider]);

        $request = (new \ReflectionMethod(HttpKernel::class, 'matchRoute'))->invoke($kernel, '/test/42', 'GET');

        self::assertInstanceOf(Request::class, $request);
        self::assertSame($entity, $request->attributes->get('id'));
    }

    #[Test]
    public function booted_kernel_routes_include_provider_owned_request_surfaces(): void
    {
        $this->writeInstalledPackageProviders([
            'waaseyaa/api' => ['Waaseyaa\\Api\\ApiServiceProvider'],
            'waaseyaa/graphql' => ['Waaseyaa\\GraphQL\\GraphQlServiceProvider'],
            'waaseyaa/user' => ['Waaseyaa\\User\\UserServiceProvider'],
        ]);

        $kernel = new HttpKernel($this->projectRoot);

        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
        $boot->invoke($kernel);

        $entityTypeManagerProperty = new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager');
        $entityTypeManager = $entityTypeManagerProperty->getValue($kernel);

        $providersProperty = new \ReflectionProperty(AbstractKernel::class, 'providers');
        $providers = $providersProperty->getValue($kernel);

        $router = new \Waaseyaa\Routing\WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        (new BuiltinRouteRegistrar($entityTypeManager, $providers))->register($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.discovery'));
        $this->assertNotNull($routes->get('graphql.endpoint'));
        // api.user.me, api.auth.login, api.auth.logout moved to AuthServiceProvider
        // (requires Twig/AuthMailer infrastructure not available in this minimal test).
        // Those routes are covered by AuthServiceProvider's own tests and SsrHttpKernelIntegrationTest.
    }

    /**
     * @param array<string, list<string>> $providersByPackage
     */
    private function writeInstalledPackageProviders(array $providersByPackage): void
    {
        $packages = [];

        foreach ($providersByPackage as $packageName => $providers) {
            $packages[] = [
                'name' => $packageName,
                'extra' => [
                    'waaseyaa' => [
                        'providers' => $providers,
                    ],
                ],
            ];
        }

        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function allows_wildcard_upload_mime_types(): void
    {
        $router = $this->createMediaRouter();
        $this->assertTrue($router->isAllowedMimeType('image/jpeg', ['image/*']));
        $this->assertTrue($router->isAllowedMimeType('application/pdf', ['image/*', 'application/pdf']));
        $this->assertFalse($router->isAllowedMimeType('text/html', ['image/*', 'application/pdf']));
    }

    #[Test]
    public function resolves_files_root_dir_defaults_to_storage_files(): void
    {
        $router = $this->createMediaRouter(projectRoot: '/var/www/myapp');
        $this->assertSame('/var/www/myapp/storage/files', $router->resolveFilesRootDir());
    }

    #[Test]
    public function resolves_files_root_dir_uses_configured_path_when_set(): void
    {
        $router = $this->createMediaRouter(
            projectRoot: '/var/www/myapp',
            config: ['files_root' => '/mnt/uploads'],
        );
        $this->assertSame('/mnt/uploads', $router->resolveFilesRootDir());
    }

    #[Test]
    public function builds_public_file_url_from_public_uri(): void
    {
        $router = $this->createMediaRouter();
        $this->assertSame('/files/images/photo.jpg', $router->buildPublicFileUrl('public://images/photo.jpg'));
        $this->assertSame('/files/tmp/doc.pdf', $router->buildPublicFileUrl('tmp/doc.pdf'));
    }

    #[Test]
    public function resolves_render_cache_max_age_from_config_or_default(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_max_age' => 600]]);
        $this->assertSame(600, $resolver->resolveRenderCacheMaxAge());

        $resolverDefault = new CacheConfigResolver([]);
        $this->assertSame(300, $resolverDefault->resolveRenderCacheMaxAge());
    }

    #[Test]
    public function render_cache_control_header_depends_on_authentication(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(
            'public, max-age=120, s-maxage=120, stale-while-revalidate=60, stale-if-error=600',
            $resolver->cacheControlHeaderForRender(new AnonymousUser(), 120),
        );
        $this->assertSame('private, no-store', $resolver->cacheControlHeaderForRender(new DevAdminAccount(), 120));
    }

    #[Test]
    public function render_cache_control_header_honors_shared_and_stale_config(): void
    {
        $resolver = new CacheConfigResolver([
            'ssr' => [
                'cache_shared_max_age' => 900,
                'cache_stale_while_revalidate' => 180,
                'cache_stale_if_error' => 3600,
            ],
        ]);

        $this->assertSame(
            'public, max-age=300, s-maxage=900, stale-while-revalidate=180, stale-if-error=3600',
            $resolver->cacheControlHeaderForRender(new AnonymousUser(), 300),
        );
    }

    #[Test]
    public function render_surrogate_headers_include_workflow_and_graph_dimensions(): void
    {
        $handler = $this->createSsrPageHandler();
        $headers = $handler->buildRenderSurrogateHeaders(
            'node',
            '42',
            'full',
            'en',
            'v2:en:full:public:published:abc123',
            [
                'workflow_visibility' => ['state' => 'published'],
                'relationship_navigation' => [
                    'entity' => ['outbound' => [['relationship_id' => '9']]],
                ],
            ],
        );

        $this->assertArrayHasKey('Surrogate-Key', $headers);
        $this->assertArrayHasKey('X-Waaseyaa-Render-Variant', $headers);
        $this->assertArrayHasKey('X-Waaseyaa-Render-Workflow', $headers);
        $this->assertStringContainsString('waaseyaa:ssr:entity:node:42', $headers['Surrogate-Key']);
        $this->assertStringContainsString('waaseyaa:ssr:workflow:published', $headers['Surrogate-Key']);
        $this->assertStringContainsString('waaseyaa:ssr:graph:', $headers['Surrogate-Key']);
        $this->assertSame('v2:en:full:public:published:abc123', $headers['X-Waaseyaa-Render-Variant']);
        $this->assertSame('published', $headers['X-Waaseyaa-Render-Workflow']);
    }

    #[Test]
    public function render_language_resolution_uses_url_prefix_and_strips_alias_lookup_path(): void
    {
        $resolver = $this->createLanguageResolver([
            'i18n' => [
                'languages' => [
                    ['id' => 'en', 'label' => 'English', 'is_default' => true],
                    ['id' => 'fr', 'label' => 'French', 'is_default' => false],
                ],
            ],
        ]);

        $request = Request::create('/fr/teachings/water');
        $resolved = $resolver->resolveRenderLanguageAndAliasPath('/fr/teachings/water', $request);

        $this->assertSame('fr', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function render_language_resolution_uses_accept_language_when_no_url_prefix(): void
    {
        $resolver = $this->createLanguageResolver([
            'i18n' => [
                'languages' => [
                    ['id' => 'en', 'label' => 'English', 'is_default' => true],
                    ['id' => 'fr', 'label' => 'French', 'is_default' => false],
                ],
            ],
        ]);

        $request = Request::create('/teachings/water');
        $request->headers->set('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.8');
        $resolved = $resolver->resolveRenderLanguageAndAliasPath('/teachings/water', $request);

        $this->assertSame('fr', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function render_language_resolution_defaults_to_english_when_not_configured(): void
    {
        $resolver = $this->createLanguageResolver();

        $request = Request::create('/teachings/water');
        $resolved = $resolver->resolveRenderLanguageAndAliasPath('/teachings/water', $request);

        $this->assertSame('en', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function parses_relationship_types_from_comma_separated_query_string(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $types = $handler->parseRelationshipTypesQuery('references, influences, ,references');
        $this->assertSame(['references', 'influences', 'references'], $types);
    }

    #[Test]
    public function parses_relationship_types_from_array_query_value(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $types = $handler->parseRelationshipTypesQuery(['references', 'influences', 'references', '', 123]);
        $this->assertSame(['references', 'influences'], $types);
    }

    #[Test]
    public function discovery_cache_key_is_deterministic_for_equivalent_option_order(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());

        $keyA = $handler->buildDiscoveryCacheKey('timeline', 'node', '1', [
            'status' => 'published',
            'direction' => 'both',
            'from' => 100,
            'to' => 200,
            'relationship_types' => ['references', 'influences'],
        ]);
        $keyB = $handler->buildDiscoveryCacheKey('timeline', 'node', '1', [
            'relationship_types' => ['references', 'influences'],
            'to' => 200,
            'from' => 100,
            'direction' => 'both',
            'status' => 'published',
        ]);

        $this->assertSame($keyA, $keyB);
    }

    #[Test]
    public function discovery_cache_key_changes_when_filter_values_change(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());

        $keyA = $handler->buildDiscoveryCacheKey('hub', 'node', '1', ['status' => 'published', 'limit' => 10]);
        $keyB = $handler->buildDiscoveryCacheKey('hub', 'node', '1', ['status' => 'published', 'limit' => 20]);

        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function discovery_payload_contract_meta_is_added_when_missing(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $payload = $handler->withDiscoveryContractMeta(['data' => ['source' => ['type' => 'node', 'id' => '1']]]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('discovery_api', $payload['meta']['surface']);
    }

    #[Test]
    public function discovery_payload_contract_meta_preserves_existing_surface(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $payload = $handler->withDiscoveryContractMeta([
            'data' => [],
            'meta' => ['surface' => 'custom_surface', 'count' => 3],
        ]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('custom_surface', $payload['meta']['surface']);
        $this->assertSame(3, $payload['meta']['count']);
    }

    #[Test]
    public function discovery_cache_tags_include_surface_entity_and_filters(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $tags = $handler->buildDiscoveryCacheTags([
            'data' => [
                'data' => [
                    'source' => ['type' => 'node', 'id' => '42'],
                ],
            ],
            'meta' => [
                'surface' => 'discovery_api',
                'filters' => ['status' => 'published', 'direction' => 'both'],
            ],
        ]);

        $this->assertContains('discovery', $tags);
        $this->assertContains('discovery:contract:v1.0', $tags);
        $this->assertContains('discovery:surface:discovery_api', $tags);
        $this->assertContains('discovery:entity:node', $tags);
        $this->assertContains('discovery:entity:node:42', $tags);
        $this->assertContains('discovery:status:published', $tags);
        $this->assertContains('discovery:direction:both', $tags);
    }


    #[Test]
    public function discovery_cache_listener_uses_tag_invalidation_when_available(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $registrar->registerDiscoveryCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(9, 'node')),
            EntityEvents::POST_SAVE->value,
        );

        $this->assertSame(0, $cache->deleteAllCalls);
        $this->assertNotEmpty($cache->invalidatedTags);
        $this->assertContains('discovery', $cache->invalidatedTags);
        $this->assertContains('discovery:entity:node', $cache->invalidatedTags);
        $this->assertContains('discovery:entity:node:9', $cache->invalidatedTags);
    }

    #[Test]
    public function discovery_cache_listener_falls_back_to_delete_all_for_non_tag_backend(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestNonTagCacheBackend();
        $registrar->registerDiscoveryCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(5, 'node')),
            EntityEvents::POST_DELETE->value,
        );

        $this->assertSame(1, $cache->deleteAllCalls);
    }

    #[Test]
    public function mcp_read_cache_listener_uses_tag_invalidation_when_available(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $registrar->registerMcpReadCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(11, 'node')),
            EntityEvents::POST_SAVE->value,
        );

        $this->assertSame(0, $cache->deleteAllCalls);
        $this->assertContains('mcp_read', $cache->invalidatedTags);
        $this->assertContains('mcp_read:entity:node', $cache->invalidatedTags);
        $this->assertContains('mcp_read:entity:node:11', $cache->invalidatedTags);
    }

    #[Test]
    public function mcp_read_cache_listener_falls_back_to_delete_all_for_non_tag_backend(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestNonTagCacheBackend();
        $registrar->registerMcpReadCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(12, 'node')),
            EntityEvents::POST_DELETE->value,
        );

        $this->assertSame(1, $cache->deleteAllCalls);
    }

    #[Test]
    public function ssr_cache_variant_langcode_is_deterministic_for_equivalent_context_order(): void
    {
        $handler = $this->createSsrPageHandler();

        $variantA = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => [
                'state' => 'published',
                'preview_requested' => false,
                'is_public' => true,
            ],
            'relationship_navigation' => [
                'entity' => ['counts' => ['outbound' => 1, 'inbound' => 2]],
                'contract' => ['version' => 'v1.0', 'surface' => 'ssr_relationship_navigation'],
            ],
        ]);
        $variantB = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'relationship_navigation' => [
                'contract' => ['surface' => 'ssr_relationship_navigation', 'version' => 'v1.0'],
                'entity' => ['counts' => ['inbound' => 2, 'outbound' => 1]],
            ],
            'workflow_visibility' => [
                'is_public' => true,
                'preview_requested' => false,
                'state' => 'published',
            ],
        ]);

        $this->assertSame($variantA, $variantB);
    }

    #[Test]
    public function ssr_cache_variant_langcode_changes_with_workflow_or_graph_dimensions(): void
    {
        $handler = $this->createSsrPageHandler();

        $published = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $review = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'review'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $differentGraph = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 3]]],
        ]);
        $previewVariant = $handler->buildSsrCacheVariantLangcode('en', 'full', true, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $teaserVariant = $handler->buildSsrCacheVariantLangcode('en', 'teaser', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);

        $this->assertNotSame($published, $review);
        $this->assertNotSame($published, $differentGraph);
        $this->assertNotSame($published, $previewVariant);
        $this->assertNotSame($published, $teaserVariant);
        $this->assertStringStartsWith('v2:en:full:public:published:', $published);
    }

    #[Test]
    public function discovery_cache_tags_include_related_entities_for_invalidation_coverage(): void
    {
        $handler = new DiscoveryApiHandler(new EntityTypeManager(new EventDispatcher()), DBALDatabase::createSqlite());
        $tags = $handler->buildDiscoveryCacheTags([
            'data' => [
                'source' => ['type' => 'node', 'id' => '1'],
                'items' => [
                    ['related_entity_type' => 'node', 'related_entity_id' => '2'],
                ],
                'clusters' => [[
                    'related_entities' => [
                        ['type' => 'node', 'id' => '3'],
                    ],
                ]],
            ],
            'meta' => [
                'surface' => 'discovery_api',
                'filters' => ['status' => 'published', 'direction' => 'both'],
            ],
        ]);

        $this->assertContains('discovery:entity:node:1', $tags);
        $this->assertContains('discovery:entity:node:2', $tags);
        $this->assertContains('discovery:entity:node:3', $tags);
        $this->assertContains('discovery:status:published', $tags);
        $this->assertContains('discovery:direction:both', $tags);
    }

    private function createMediaRouter(
        string $projectRoot = '/tmp/test-project',
        array $config = [],
    ): \Waaseyaa\Media\Http\Router\MediaRouter {
        return new \Waaseyaa\Media\Http\Router\MediaRouter($projectRoot, $config);
    }

    private function createLanguageResolver(array $config = []): LanguageResolver
    {
        $languageDefs = $config['i18n']['languages'] ?? [
            ['id' => 'en', 'label' => 'English', 'is_default' => true],
        ];
        $languages = array_map(
            static fn(array $def) => new Language(
                id: $def['id'],
                label: $def['label'],
                isDefault: $def['is_default'] ?? false,
            ),
            $languageDefs,
        );
        $manager = new LanguageManager($languages);

        $serviceResolver = new class ($manager) implements \Waaseyaa\Foundation\Http\HttpServiceResolverInterface {
            public function __construct(private readonly LanguageManager $manager) {}

            public function resolve(string $className): ?object
            {
                return $className === LanguageManagerInterface::class ? $this->manager : null;
            }
        };

        return new LanguageResolver(serviceResolver: $serviceResolver);
    }

    private function createSsrPageHandler(array $config = []): SsrPageHandler
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);
        $cacheConfigResolver = new CacheConfigResolver($config);

        // Build a LanguageManager from config or default to English-only.
        $languageDefs = $config['i18n']['languages'] ?? [
            ['id' => 'en', 'label' => 'English', 'is_default' => true],
        ];
        $languages = array_map(
            static fn(array $def) => new Language(
                id: $def['id'],
                label: $def['label'],
                isDefault: $def['is_default'] ?? false,
            ),
            $languageDefs,
        );
        $manager = new LanguageManager($languages);

        $serviceResolver = new class ($manager) implements \Waaseyaa\Foundation\Http\HttpServiceResolverInterface {
            public function __construct(private readonly LanguageManager $manager) {}

            public function resolve(string $className): ?object
            {
                return $className === LanguageManagerInterface::class ? $this->manager : null;
            }
        };

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: $cacheConfigResolver,
            discoveryHandler: $discoveryHandler,
            projectRoot: '/tmp/test-project',
            config: $config,
            serviceResolver: $serviceResolver,
        );
    }
}

final class TestKernelEntity implements EntityInterface
{
    public function __construct(
        private readonly int|string|null $entityId,
        private readonly string $entityTypeId,
    ) {}

    public function id(): int|string|null { return $this->entityId; }
    public function uuid(): string { return ''; }
    public function label(): string { return 'test'; }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return null; }
    public function set(string $name, mixed $value): static { return $this; }
    public function toArray(): array { return ['id' => $this->entityId]; }
    public function language(): string { return 'en'; }
}

final class TestTagAwareCacheBackend implements TagAwareCacheInterface
{
    /** @var list<string> */
    public array $invalidatedTags = [];
    public int $deleteAllCalls = 0;

    public function get(string $cid): CacheItem|false { return false; }
    public function getMultiple(array &$cids): array { return []; }
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void {}
    public function delete(string $cid): void {}
    public function deleteMultiple(array $cids): void {}
    public function deleteAll(): void { $this->deleteAllCalls++; }
    public function invalidate(string $cid): void {}
    public function invalidateMultiple(array $cids): void {}
    public function invalidateAll(): void {}
    public function removeBin(): void {}

    public function invalidateByTags(array $tags): void
    {
        $this->invalidatedTags = array_values(array_unique(array_merge($this->invalidatedTags, $tags)));
    }
}

final class TestNonTagCacheBackend implements CacheBackendInterface
{
    public int $deleteAllCalls = 0;

    public function get(string $cid): CacheItem|false { return false; }
    public function getMultiple(array &$cids): array { return []; }
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void {}
    public function delete(string $cid): void {}
    public function deleteMultiple(array $cids): void {}
    public function deleteAll(): void { $this->deleteAllCalls++; }
    public function invalidate(string $cid): void {}
    public function invalidateMultiple(array $cids): void {}
    public function invalidateAll(): void {}
    public function removeBin(): void {}
}
