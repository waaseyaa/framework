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
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;

#[CoversClass(HttpKernel::class)]
final class HttpKernelTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_http_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', "<?php return ['database' => ':memory:'];");
        file_put_contents($this->projectRoot . '/config/entity-types.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
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
    public function handle_is_never_return_type(): void
    {
        $ref = new \ReflectionMethod(HttpKernel::class, 'handle');

        $this->assertSame('never', $ref->getReturnType()?->getName());
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
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'resolveCorsHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($kernel, 'http://localhost:3000', ['http://localhost:3000'], false);

        $this->assertCount(5, $headers);
        $this->assertContains('Access-Control-Allow-Origin: http://localhost:3000', $headers);
        $this->assertContains('Vary: Origin', $headers);
    }

    #[Test]
    public function resolve_cors_headers_for_disallowed_origin_returns_empty_list(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'resolveCorsHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($kernel, 'http://evil.test', ['http://localhost:3000'], false);

        $this->assertSame([], $headers);
    }

    #[Test]
    public function resolve_cors_headers_allows_localhost_any_port_in_development_mode(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'resolveCorsHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($kernel, 'http://localhost:4321', ['http://localhost:3000'], true);
        $this->assertContains('Access-Control-Allow-Origin: http://localhost:4321', $headers);

        $headersLoopback = $method->invoke($kernel, 'http://127.0.0.1:5173', ['http://localhost:3000'], true);
        $this->assertContains('Access-Control-Allow-Origin: http://127.0.0.1:5173', $headersLoopback);
    }

    #[Test]
    public function resolve_cors_headers_does_not_allow_non_localhost_in_development_mode(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'resolveCorsHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($kernel, 'http://example.com:3001', ['http://localhost:3000'], true);
        $this->assertSame([], $headers);
    }

    #[Test]
    public function detects_cors_preflight_request_method(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'isCorsPreflightRequest');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($kernel, 'OPTIONS'));
        $this->assertTrue($method->invoke($kernel, 'options'));
        $this->assertFalse($method->invoke($kernel, 'GET'));
    }

    #[Test]
    public function detects_development_mode_from_common_environment_names(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);

        $method = new \ReflectionMethod(HttpKernel::class, 'isDevelopmentMode');
        $method->setAccessible(true);

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
        $configProp->setAccessible(true);
        $configProp->setValue($kernel, ['environment' => 'development']);

        $method = new \ReflectionMethod(HttpKernel::class, 'shouldUseDevFallbackAccount');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($kernel, 'cli-server'));
    }

    #[Test]
    public function dev_fallback_account_requires_development_mode_flag_and_cli_server_sapi(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');

        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);

        $method = new \ReflectionMethod(HttpKernel::class, 'shouldUseDevFallbackAccount');
        $method->setAccessible(true);

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
    public function registers_core_routes_on_router(): void
    {
        $kernel = new HttpKernel($this->projectRoot);

        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
        $boot->setAccessible(true);
        $boot->invoke($kernel);

        $router = new \Waaseyaa\Routing\WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        $registerRoutes = new \ReflectionMethod(HttpKernel::class, 'registerRoutes');
        $registerRoutes->setAccessible(true);
        $registerRoutes->invoke($kernel, $router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.schema.show'));
        $this->assertNotNull($routes->get('api.openapi'));
        $this->assertNotNull($routes->get('api.entity_types'));
        $this->assertNotNull($routes->get('api.broadcast'));
        $this->assertNotNull($routes->get('api.search'));
        $this->assertNotNull($routes->get('api.discovery.hub'));
        $this->assertNotNull($routes->get('api.discovery.cluster'));
        $this->assertNotNull($routes->get('api.discovery.timeline'));
        $this->assertNotNull($routes->get('api.discovery.endpoint'));
        $this->assertNotNull($routes->get('api.media.upload'));
        $this->assertNotNull($routes->get('mcp.endpoint'));
        $this->assertNotNull($routes->get('public.home'));
        $this->assertNotNull($routes->get('public.page'));
        $this->assertTrue((bool) $routes->get('public.home')?->getOption('_render'));
    }

    #[Test]
    public function allows_wildcard_upload_mime_types(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'isAllowedMimeType');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($kernel, 'image/jpeg', ['image/*']));
        $this->assertTrue($method->invoke($kernel, 'application/pdf', ['image/*', 'application/pdf']));
        $this->assertFalse($method->invoke($kernel, 'text/html', ['image/*', 'application/pdf']));
    }

    #[Test]
    public function builds_public_file_url_from_public_uri(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildPublicFileUrl');
        $method->setAccessible(true);

        $this->assertSame('/files/images/photo.jpg', $method->invoke($kernel, 'public://images/photo.jpg'));
        $this->assertSame('/files/tmp/doc.pdf', $method->invoke($kernel, 'tmp/doc.pdf'));
    }

    #[Test]
    public function sanitizes_uploaded_filename(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'sanitizeUploadFilename');
        $method->setAccessible(true);

        $this->assertSame('my_photo_.jpg', $method->invoke($kernel, 'my photo?.jpg'));
        $this->assertSame('upload.bin', $method->invoke($kernel, '../../'));
    }

    #[Test]
    public function resolves_render_cache_max_age_from_config_or_default(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);
        $method = new \ReflectionMethod(HttpKernel::class, 'resolveRenderCacheMaxAge');
        $method->setAccessible(true);

        $configProp->setValue($kernel, ['ssr' => ['cache_max_age' => 600]]);
        $this->assertSame(600, $method->invoke($kernel));

        $configProp->setValue($kernel, []);
        $this->assertSame(300, $method->invoke($kernel));
    }

    #[Test]
    public function render_cache_control_header_depends_on_authentication(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'cacheControlHeaderForRender');
        $method->setAccessible(true);

        $this->assertSame('public, max-age=120', $method->invoke($kernel, new AnonymousUser(), 120));
        $this->assertSame('private, no-store', $method->invoke($kernel, new DevAdminAccount(), 120));
    }

    #[Test]
    public function render_language_resolution_uses_url_prefix_and_strips_alias_lookup_path(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);
        $configProp->setValue($kernel, [
            'i18n' => [
                'languages' => [
                    ['id' => 'en', 'label' => 'English', 'is_default' => true],
                    ['id' => 'fr', 'label' => 'French', 'is_default' => false],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(HttpKernel::class, 'resolveRenderLanguageAndAliasPath');
        $method->setAccessible(true);

        $request = Request::create('/fr/teachings/water');
        $resolved = $method->invoke($kernel, '/fr/teachings/water', $request);

        $this->assertSame('fr', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function render_language_resolution_uses_accept_language_when_no_url_prefix(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);
        $configProp->setValue($kernel, [
            'i18n' => [
                'languages' => [
                    ['id' => 'en', 'label' => 'English', 'is_default' => true],
                    ['id' => 'fr', 'label' => 'French', 'is_default' => false],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(HttpKernel::class, 'resolveRenderLanguageAndAliasPath');
        $method->setAccessible(true);

        $request = Request::create('/teachings/water');
        $request->headers->set('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.8');
        $resolved = $method->invoke($kernel, '/teachings/water', $request);

        $this->assertSame('fr', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function render_language_resolution_defaults_to_english_when_not_configured(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $configProp = new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'config');
        $configProp->setAccessible(true);
        $configProp->setValue($kernel, []);

        $method = new \ReflectionMethod(HttpKernel::class, 'resolveRenderLanguageAndAliasPath');
        $method->setAccessible(true);

        $request = Request::create('/teachings/water');
        $resolved = $method->invoke($kernel, '/teachings/water', $request);

        $this->assertSame('en', $resolved['langcode']);
        $this->assertSame('/teachings/water', $resolved['alias_path']);
    }

    #[Test]
    public function parses_relationship_types_from_comma_separated_query_string(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'parseRelationshipTypesQuery');
        $method->setAccessible(true);

        $types = $method->invoke($kernel, 'references, influences, ,references');

        $this->assertSame(['references', 'influences', 'references'], $types);
    }

    #[Test]
    public function parses_relationship_types_from_array_query_value(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'parseRelationshipTypesQuery');
        $method->setAccessible(true);

        $types = $method->invoke($kernel, ['references', 'influences', 'references', '', 123]);

        $this->assertSame(['references', 'influences'], $types);
    }

    #[Test]
    public function discovery_cache_key_is_deterministic_for_equivalent_option_order(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildDiscoveryCacheKey');
        $method->setAccessible(true);

        $keyA = $method->invoke($kernel, 'timeline', 'node', '1', [
            'status' => 'published',
            'direction' => 'both',
            'from' => 100,
            'to' => 200,
            'relationship_types' => ['references', 'influences'],
        ]);
        $keyB = $method->invoke($kernel, 'timeline', 'node', '1', [
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
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildDiscoveryCacheKey');
        $method->setAccessible(true);

        $keyA = $method->invoke($kernel, 'hub', 'node', '1', ['status' => 'published', 'limit' => 10]);
        $keyB = $method->invoke($kernel, 'hub', 'node', '1', ['status' => 'published', 'limit' => 20]);

        $this->assertNotSame($keyA, $keyB);
    }

    #[Test]
    public function discovery_payload_contract_meta_is_added_when_missing(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'withDiscoveryContractMeta');
        $method->setAccessible(true);

        $payload = $method->invoke($kernel, ['data' => ['source' => ['type' => 'node', 'id' => '1']]]);

        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('discovery_api', $payload['meta']['surface']);
    }

    #[Test]
    public function discovery_payload_contract_meta_preserves_existing_surface(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'withDiscoveryContractMeta');
        $method->setAccessible(true);

        $payload = $method->invoke($kernel, [
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
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildDiscoveryCacheTags');
        $method->setAccessible(true);

        $tags = $method->invoke($kernel, [
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
        $kernel = new HttpKernel('/tmp/test-project');
        $dispatcher = new EventDispatcher();

        $dispatcherProp = new \ReflectionProperty(AbstractKernel::class, 'dispatcher');
        $dispatcherProp->setAccessible(true);
        $dispatcherProp->setValue($kernel, $dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $method = new \ReflectionMethod(HttpKernel::class, 'registerDiscoveryCacheListeners');
        $method->setAccessible(true);
        $method->invoke($kernel, $cache);

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
        $kernel = new HttpKernel('/tmp/test-project');
        $dispatcher = new EventDispatcher();

        $dispatcherProp = new \ReflectionProperty(AbstractKernel::class, 'dispatcher');
        $dispatcherProp->setAccessible(true);
        $dispatcherProp->setValue($kernel, $dispatcher);

        $cache = new TestNonTagCacheBackend();
        $method = new \ReflectionMethod(HttpKernel::class, 'registerDiscoveryCacheListeners');
        $method->setAccessible(true);
        $method->invoke($kernel, $cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(5, 'node')),
            EntityEvents::POST_DELETE->value,
        );

        $this->assertSame(1, $cache->deleteAllCalls);
    }

    #[Test]
    public function ssr_cache_variant_langcode_is_deterministic_for_equivalent_context_order(): void
    {
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildSsrCacheVariantLangcode');
        $method->setAccessible(true);

        $variantA = $method->invoke($kernel, 'en', 'full', false, [
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
        $variantB = $method->invoke($kernel, 'en', 'full', false, [
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
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildSsrCacheVariantLangcode');
        $method->setAccessible(true);

        $published = $method->invoke($kernel, 'en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $review = $method->invoke($kernel, 'en', 'full', false, [
            'workflow_visibility' => ['state' => 'review'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $differentGraph = $method->invoke($kernel, 'en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 3]]],
        ]);
        $previewVariant = $method->invoke($kernel, 'en', 'full', true, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $teaserVariant = $method->invoke($kernel, 'en', 'teaser', false, [
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
        $kernel = new HttpKernel('/tmp/test-project');
        $method = new \ReflectionMethod(HttpKernel::class, 'buildDiscoveryCacheTags');
        $method->setAccessible(true);

        $tags = $method->invoke($kernel, [
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
