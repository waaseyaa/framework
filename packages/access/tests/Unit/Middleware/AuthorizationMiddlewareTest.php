<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(AuthorizationMiddleware::class)]
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
}
