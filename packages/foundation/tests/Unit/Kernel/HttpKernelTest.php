<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

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
        $this->assertNotNull($routes->get('api.media.upload'));
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

}
