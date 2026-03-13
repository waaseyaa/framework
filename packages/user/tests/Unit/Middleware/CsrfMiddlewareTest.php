<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;

#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;
    private HttpHandlerInterface $passthrough;

    protected function setUp(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            // Use a mock session for tests.
            $_SESSION = [];
        }

        $this->middleware = new CsrfMiddleware();

        $this->passthrough = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('OK', 200);
            }
        };
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function getRequestsPassThrough(): void
    {
        $request = Request::create('/page', 'GET');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postWithoutTokenReturns403(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/submit', 'POST');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function postWithValidTokenPassesThrough(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/submit', 'POST', ['_csrf_token' => $token]);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postWithValidHeaderTokenPassesThrough(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/submit', 'POST');
        $request->headers->set('X-CSRF-Token', $token);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postWithInvalidTokenReturns403(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/submit', 'POST', ['_csrf_token' => 'wrong-token']);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function csrfDisabledRouteSkipsValidation(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $route = new Route('/api/endpoint');
        $route->setOption('_csrf', false);

        $request = Request::create('/api/endpoint', 'POST');
        $request->attributes->set('_route_object', $route);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function putAndDeleteRequireToken(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $putRequest = Request::create('/resource/1', 'PUT');
        $this->assertSame(403, $this->middleware->process($putRequest, $this->passthrough)->getStatusCode());

        $deleteRequest = Request::create('/resource/1', 'DELETE');
        $this->assertSame(403, $this->middleware->process($deleteRequest, $this->passthrough)->getStatusCode());
    }

    #[Test]
    public function renderRouteReturnsHtmlError(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $route = new Route('/form');
        $route->setOption('_render', true);

        $request = Request::create('/form', 'POST');
        $request->attributes->set('_route_object', $route);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Invalid Security Token', $response->getContent());
    }

    #[Test]
    public function apiRouteReturnsJsonError(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/api/submit', 'POST');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function tokenStaticMethodReturnsConsistentToken(): void
    {
        $_SESSION = [];
        $token1 = CsrfMiddleware::token();
        $token2 = CsrfMiddleware::token();

        $this->assertSame($token1, $token2);
        $this->assertSame(64, strlen($token1)); // 32 bytes = 64 hex chars
    }

    #[Test]
    public function regenerateChangesToken(): void
    {
        $_SESSION = [];
        $original = CsrfMiddleware::token();
        CsrfMiddleware::regenerate();
        $regenerated = CsrfMiddleware::token();

        $this->assertNotSame($original, $regenerated);
    }

    #[Test]
    public function postWithJsonApiContentTypeSkipsCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/api/nodes', 'POST', [], [], [], [], '{"data":{}}');
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function putWithJsonApiContentTypeSkipsCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/api/nodes/1', 'PUT', [], [], [], [], '{"data":{}}');
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function deleteWithJsonApiContentTypeSkipsCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/api/nodes/1', 'DELETE');
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function postWithFormUrlencodedStillRequiresCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/submit', 'POST', ['field' => 'value']);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function postWithMultipartFormDataStillRequiresCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }
}
