<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
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

}
