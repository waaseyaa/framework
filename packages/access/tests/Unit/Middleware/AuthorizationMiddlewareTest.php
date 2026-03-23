<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\RedirectValidator;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(AuthorizationMiddleware::class)]
#[CoversClass(RedirectValidator::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    #[Test]
    public function returns_403_when_access_is_forbidden(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_permission', 'access content');

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403', $response->getContent());
    }

    #[Test]
    public function passes_through_when_access_is_allowed(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_public', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('downstream', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('downstream', $response->getContent());
    }

    #[Test]
    public function passes_through_when_no_access_requirements(): void
    {
        $route = new Route('/api/test');

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/test');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('open', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passes_through_when_no_route_object(): void
    {
        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/test');
        $request->attributes->set('_account', $account);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('no route', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns_401_when_authentication_required_and_anonymous(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node', 'POST');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('401', $response->getContent());
        $this->assertSame(
            'Bearer realm="Waaseyaa API"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    #[Test]
    public function returns_403_when_no_account_set(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_permission', 'access content');

        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('No authenticated account', $response->getContent());
    }

    #[Test]
    public function render_route_401_redirects_to_login(): void
    {
        $route = new Route('/dashboard');
        $route->setOption('_authenticated', true);
        $route->setOption('_render', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/dashboard');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?redirect=%2Fdashboard', $response->headers->get('Location'));
    }

    #[Test]
    public function render_route_403_returns_html_error_page(): void
    {
        $route = new Route('/admin/settings');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_render', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/admin/settings');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Forbidden', $response->getContent());
        $this->assertStringContainsString('Sign in', $response->getContent());
    }

    #[Test]
    public function api_route_403_still_returns_json(): void
    {
        $route = new Route('/api/admin');
        $route->setOption('_permission', 'administer site');

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/admin');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function render_route_403_uses_error_page_renderer_when_available(): void
    {
        $route = new Route('/admin/settings');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_render', true);

        $renderer = new class implements ErrorPageRendererInterface {
            public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
            {
                return new Response("<h1>{$statusCode} {$title}</h1><p>{$detail}</p>", $statusCode, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
        };

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker, $renderer);

        $request = Request::create('/admin/settings');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403 Forbidden', $response->getContent());
        // Should NOT contain the hardcoded fallback styling.
        $this->assertStringNotContainsString('#111827', $response->getContent());
    }

    #[Test]
    public function render_route_403_falls_back_when_renderer_returns_null(): void
    {
        $route = new Route('/admin/settings');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_render', true);

        $renderer = new class implements ErrorPageRendererInterface {
            public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
            {
                return null;
            }
        };

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker, $renderer);

        $request = Request::create('/admin/settings');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        // Falls back to hardcoded HTML with the dark background.
        $this->assertStringContainsString('#111827', $response->getContent());
        $this->assertStringContainsString('Forbidden', $response->getContent());
    }

    #[Test]
    public function api_route_403_ignores_renderer(): void
    {
        $route = new Route('/api/admin');
        $route->setOption('_permission', 'administer site');

        $renderer = new class implements ErrorPageRendererInterface {
            public bool $called = false;

            public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
            {
                $this->called = true;

                return new Response('themed error');
            }
        };

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker, $renderer);

        $request = Request::create('/api/admin');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
        $this->assertFalse($renderer->called);
    }

    // -----------------------------------------------------------------
    // XSS prevention (#542)
    // -----------------------------------------------------------------

    #[Test]
    public function render_route_html_error_escapes_xss_in_detail(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_render', true);

        // Use a custom renderer that returns null to force fallback,
        // after injecting a malicious detail via the forbidden reason.
        $renderer = new class implements ErrorPageRendererInterface {
            public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
            {
                return null;
            }
        };

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker, $renderer);

        $request = Request::create('/<script>alert(1)</script>');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $content = $response->getContent();
        // The raw <script> tag must NOT appear unescaped in the HTML body.
        // The path is URL-encoded in the login link href, so it's safe there.
        // Title and detail come from the access checker (fixed strings), so no XSS vector.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $content);
    }

    // -----------------------------------------------------------------
    // Cache-Control on error responses (#547)
    // -----------------------------------------------------------------

    #[Test]
    public function json_403_has_cache_control_no_store(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_permission', 'access content');

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function json_401_has_cache_control_no_store(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node', 'POST');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function json_403_no_account_has_cache_control_no_store(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_permission', 'access content');

        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function html_403_has_cache_control_no_store(): void
    {
        $route = new Route('/admin/settings');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_render', true);

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/admin/settings');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
