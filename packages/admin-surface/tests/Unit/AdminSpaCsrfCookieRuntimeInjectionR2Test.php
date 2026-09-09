<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceHostFactoryInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Session\SessionCookiePolicy;

/**
 * Review-repair discriminators for #3047 r2 (direct HTML + dollar-name rewrite).
 */
#[CoversClass(AdminSurfaceServiceProvider::class)]
final class AdminSpaCsrfCookieRuntimeInjectionR2Test extends TestCase
{
    #[Test]
    public function packaged_html_csrf_cookie_name_is_rewritten_from_session_policy(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><body><div id="__nuxt"></div>
            <script>window.__NUXT__={};window.__NUXT__.config={public:{enableRealtime:"1",csrfCookieName:"XSRF-TOKEN"},app:{baseURL:"/admin/"}}</script>
            </body></html>
            HTML;

        $rewritten = AdminSurfaceServiceProvider::applyRuntimeCsrfCookieName(
            $html,
            SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME,
        );

        $this->assertStringContainsString(
            'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
            $rewritten,
        );
        $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $rewritten);
    }

    #[Test]
    public function dollar_sign_in_csrf_cookie_name_is_preserved_exactly(): void
    {
        $html = 'csrfCookieName:"XSRF-TOKEN"';
        $name = 'APP$1-XSRF';

        $rewritten = AdminSurfaceServiceProvider::applyRuntimeCsrfCookieName($html, $name);

        $this->assertSame('csrfCookieName:"APP$1-XSRF"', $rewritten);
        $this->assertStringNotContainsString('csrfCookieName:"APP-XSRF"', $rewritten);
    }

    #[Test]
    public function host_bound_dollar_name_is_preserved_exactly(): void
    {
        $html = 'csrfCookieName:"XSRF-TOKEN"';
        $name = '__Host-APP$1-XSRF';

        $rewritten = AdminSurfaceServiceProvider::applyRuntimeCsrfCookieName($html, $name);

        $this->assertSame('csrfCookieName:"__Host-APP$1-XSRF"', $rewritten);
    }

    #[Test]
    public function serve_static_file_rewrites_packaged_index_html_csrf_name(): void
    {
        $distIndex = dirname(__DIR__, 2) . '/dist/index.html';
        $this->assertFileExists($distIndex);
        $raw = (string) file_get_contents($distIndex);
        $this->assertStringContainsString('csrfCookieName:"XSRF-TOKEN"', $raw);

        $response = AdminSurfaceServiceProvider::serveStaticFile(
            $distIndex,
            SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME,
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString(
            'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
            $body,
        );
        $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $body);
    }

    #[Test]
    public function serve_static_file_rewrites_packaged_login_index_html(): void
    {
        $loginIndex = dirname(__DIR__, 2) . '/dist/login/index.html';
        $this->assertFileExists($loginIndex);

        $response = AdminSurfaceServiceProvider::serveStaticFile(
            $loginIndex,
            SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME,
        );
        $body = (string) $response->getContent();
        $this->assertStringContainsString(
            'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
            $body,
        );
        $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $body);
    }

    #[Test]
    public function serve_static_file_rewrites_packaged_200_html(): void
    {
        $html200 = dirname(__DIR__, 2) . '/dist/200.html';
        $this->assertFileExists($html200);

        $response = AdminSurfaceServiceProvider::serveStaticFile(
            $html200,
            SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME,
        );
        $body = (string) $response->getContent();
        $this->assertStringContainsString(
            'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
            $body,
        );
        $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $body);
    }

    /**
     * Route-level proof: the registered admin_spa controller rewrites packaged
     * HTML for direct entry paths using the same SessionCookiePolicy as the SPA fallback.
     */
    #[Test]
    public function admin_spa_route_rewrites_direct_html_entry_paths(): void
    {
        $router = $this->routesWithHostBoundSessionPolicy(['host_bound' => true]);
        $route = $router->getRouteCollection()->get('admin_spa');
        $this->assertNotNull($route);
        $controller = $route->getDefault('_controller');
        $this->assertIsCallable($controller);

        foreach (['index.html', 'login/index.html', '200.html'] as $path) {
            /** @var Response $response */
            $response = $controller(Request::create('/admin/' . $path), $path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $body = (string) $response->getContent();
            $this->assertStringContainsString(
                'csrfCookieName:"' . SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME . '"',
                $body,
                $path,
            );
            $this->assertStringNotContainsString('csrfCookieName:"XSRF-TOKEN"', $body, $path);
        }
    }

    /**
     * @param array<string, mixed> $cookieOptions
     */
    private function routesWithHostBoundSessionPolicy(array $cookieOptions): WaaseyaaRouter
    {
        $host = new class () extends AbstractAdminSurfaceHost {
            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return null;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                return new CatalogBuilder();
            }

            public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([]);
            }
        };

        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelContext(
            projectRoot: sys_get_temp_dir() . '/waaseyaa_admin_r2_' . uniqid('', true),
            config: ['session' => ['cookie' => $cookieOptions]],
            manifestFormatters: [],
        );
        $provider->setKernelServices(new class ($host) implements KernelServicesInterface {
            public function __construct(private readonly AbstractAdminSurfaceHost $host) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    AdminSurfaceHostFactoryInterface::class => new class ($this->host) implements AdminSurfaceHostFactoryInterface {
                        public function __construct(private readonly AbstractAdminSurfaceHost $host) {}

                        public function createAdminSurfaceHost(): AbstractAdminSurfaceHost
                        {
                            return $this->host;
                        }
                    },
                    FieldTypeManagerInterface::class => new FieldTypeManager(),
                    default => null,
                };
            }
        });

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $provider->routes($router, $entityTypeManager);

        return $router;
    }
}
