<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
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
            session_start();
        }

        $_SESSION = [];

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
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
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
    public function postToMcpRouteWithCsrfExemptionPassesThrough(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $route = new Route('/mcp');
        $route->setOption('_csrf', false);

        $request = Request::create('/mcp', 'POST', [], [], [], [], '{"jsonrpc":"2.0","method":"initialize","id":1}');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);
        $this->assertSame(200, $response->getStatusCode());
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

    #[Test]
    public function postWithJsonContentTypeSkipsCsrf(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $request = Request::create('/graphql', 'POST', [], [], [], [], '{"query":"{ nodes { id } }"}');
        $request->headers->set('Content-Type', 'application/json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // T005 — Content-Type × token-source matrix (10 required cases)
    // -------------------------------------------------------------------------

    #[Test]
    public function matrixCase1ApplicationJsonExempt(): void
    {
        // Case 1: application/json, no token → 200 (exempt)
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/api', 'POST', [], [], [], [], '{}');
        $request->headers->set('Content-Type', 'application/json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase2ApplicationVndApiJsonExempt(): void
    {
        // Case 2: application/vnd.api+json, no token → 200 (exempt)
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/api/nodes', 'POST', [], [], [], [], '{"data":{}}');
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase3FormUrlencodedCorrectFieldToken(): void
    {
        // Case 3: application/x-www-form-urlencoded, _csrf_token field correct → 200
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/submit', 'POST', ['_csrf_token' => $token]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase4FormUrlencodedWrongFieldToken(): void
    {
        // Case 4: application/x-www-form-urlencoded, _csrf_token field wrong → 403
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/submit', 'POST', ['_csrf_token' => 'wrong-value']);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase5MultipartCorrectFieldToken(): void
    {
        // Case 5: multipart/form-data, _csrf_token field correct → 200
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/upload', 'POST', ['_csrf_token' => $token]);
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase6MultipartCorrectXCsrfTokenHeader(): void
    {
        // Case 6: multipart/form-data, X-CSRF-Token correct → 200
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $request->headers->set('X-CSRF-Token', $token);
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase7MultipartCorrectXsrfTokenHeaderUrlEncoded(): void
    {
        // Case 7: multipart/form-data, X-XSRF-TOKEN correct (URL-encoded) → 200
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $request->headers->set('X-XSRF-TOKEN', rawurlencode($token));
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase8MultipartWrongXsrfTokenHeader(): void
    {
        // Case 8: multipart/form-data, X-XSRF-TOKEN wrong → 403
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $request->headers->set('X-XSRF-TOKEN', rawurlencode('wrong-value'));
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase9MultipartNoToken(): void
    {
        // Case 9: multipart/form-data, no token → 403
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/upload', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function matrixCase10MultipartCorrectFieldAndWrongXsrfHeader(): void
    {
        // Case 10: multipart/form-data, _csrf_token correct AND X-XSRF-TOKEN wrong → 200 (any-of)
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/upload', 'POST', ['_csrf_token' => $token]);
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary');
        $request->headers->set('X-XSRF-TOKEN', rawurlencode('wrong-value'));
        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // T006 — Cookie writer: every contract §1 attribute pinned
    // Tests call the static helper directly — the live path used by HttpKernel.
    // -------------------------------------------------------------------------

    #[Test]
    public function cookieName(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('XSRF-TOKEN', $cookies[0]->getName());
    }

    #[Test]
    public function cookieValueIsRawUrlEncodedToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame(rawurlencode($token), $cookies[0]->getValue());
    }

    #[Test]
    public function cookieHttpOnlyIsFalse(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertFalse($cookies[0]->isHttpOnly());
    }

    #[Test]
    public function cookieSecureIsTrueOnHttps(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('https://example.com/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertTrue($cookies[0]->isSecure());
    }

    #[Test]
    public function cookieSecureIsFalseOnHttp(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('http://example.com/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertFalse($cookies[0]->isSecure());
    }

    #[Test]
    public function cookieSameSiteIsLax(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('lax', $cookies[0]->getSameSite());
    }

    #[Test]
    public function cookiePathIsSlash(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('/', $cookies[0]->getPath());
    }

    #[Test]
    public function cookieDomainIsNotSet(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        // Domain unset: Symfony Cookie returns null when no domain is configured.
        $this->assertNull($cookies[0]->getDomain());
    }

    #[Test]
    public function cookieIsSessionCookieNoExpiry(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        // Session cookie: expires at 0 (no explicit expiry)
        $this->assertSame(0, $cookies[0]->getExpiresTime());
    }

    #[Test]
    public function noCookieOnJsonResponse(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/api', 'GET');
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $this->assertCount(0, $response->headers->getCookies());
    }

    #[Test]
    public function noCookieOnOctetStreamResponse(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/download', 'GET');
        $response = new Response('binary', 200, ['Content-Type' => 'application/octet-stream']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $this->assertCount(0, $response->headers->getCookies());
    }

    #[Test]
    public function exactlyOneCookieOnHtmlResponse(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $xsrfCookies = array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        );
        $this->assertCount(1, $xsrfCookies);
    }

    #[Test]
    public function idempotentSecondMiddlewarePassDoesNotDuplicateCookie(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        // Call the static helper twice on the same response — should remain idempotent.
        CsrfMiddleware::attachCookieIfHtml($request, $response);
        CsrfMiddleware::attachCookieIfHtml($request, $response);

        $xsrfCookies = array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        );
        $this->assertCount(1, $xsrfCookies);
    }

    // -------------------------------------------------------------------------
    // #2177 F1 prerequisite — explicit per-route CSRF opt-in (_csrf = true)
    // takes precedence over the JSON content-type exemption.
    // -------------------------------------------------------------------------

    #[Test]
    public function jsonPostOnCsrfRequiredRouteWithoutTokenReturns403(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', [], [], [], [], '{"decision":"approve"}');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function jsonApiPostOnCsrfRequiredRouteWithoutTokenReturns403(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', [], [], [], [], '{"data":{}}');
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function jsonPostOnCsrfRequiredRouteWithValidXsrfHeaderPassesThrough(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', [], [], [], [], '{"decision":"approve"}');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-XSRF-TOKEN', rawurlencode($token));
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function jsonPostOnCsrfRequiredRouteWithValidCsrfHeaderPassesThrough(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', [], [], [], [], '{"decision":"approve"}');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-CSRF-Token', $token);
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function jsonPostOnRouteWithoutCsrfOptionRemainsExempt(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $route = new Route('/api/nodes');

        $request = Request::create('/api/nodes', 'POST', [], [], [], [], '{"data":{}}');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function formPostOnCsrfRequiredRouteWithoutTokenReturns403(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', ['decision' => 'approve']);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $request->attributes->set('_route_object', $route);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // #2177 F1 prerequisite — XSRF-TOKEN cookie delivery on authenticated
    // session/API boot responses (non-HTML), same flags as the HTML path.
    // -------------------------------------------------------------------------

    #[Test]
    public function authenticatedSessionJsonResponseDeliversXsrfCookie(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = Request::create('/api/user/me', 'GET');
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $response = $this->middleware->process($request, $this->jsonPassthrough());

        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        ));
        $this->assertCount(1, $cookies);
        $this->assertSame(rawurlencode($token), $cookies[0]->getValue());
        $this->assertFalse($cookies[0]->isHttpOnly());
        $this->assertSame('lax', $cookies[0]->getSameSite());
        $this->assertSame('/', $cookies[0]->getPath());
        $this->assertFalse($cookies[0]->isSecure());
    }

    #[Test]
    public function authenticatedSessionJsonResponseCookieIsSecureOnHttps(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('https://example.com/api/user/me', 'GET');
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $response = $this->middleware->process($request, $this->jsonPassthrough());

        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        ));
        $this->assertCount(1, $cookies);
        $this->assertTrue($cookies[0]->isSecure());
    }

    #[Test]
    public function anonymousJsonResponseDoesNotGetXsrfCookie(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/api/user/me', 'GET');
        $request->attributes->set('_account', $this->anonymousAccount());

        $response = $this->middleware->process($request, $this->jsonPassthrough());

        $this->assertCount(0, $response->headers->getCookies());
    }

    #[Test]
    public function bearerAuthenticatedJsonResponseWithoutLoginSessionDoesNotGetXsrfCookie(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/api/user/me', 'GET');
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', []);

        $response = $this->middleware->process($request, $this->jsonPassthrough());

        $this->assertCount(0, $response->headers->getCookies());
    }

    #[Test]
    public function csrfRequired403DeliversXsrfCookieToAuthenticatedSession(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('/api/approvals', 'POST', [], [], [], [], '{"decision":"approve"}');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route_object', $route);
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $response = $this->middleware->process($request, $this->passthrough);

        $this->assertSame(403, $response->getStatusCode());
        $xsrfCookies = array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        );
        $this->assertCount(1, $xsrfCookies);
    }

    #[Test]
    public function authenticatedHtmlResponseStillGetsExactlyOneXsrfCookie(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

        $request = Request::create('/page', 'GET');
        $request->attributes->set('_account', $this->authenticatedAccount());

        $htmlPassthrough = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
        };

        $response = $this->middleware->process($request, $htmlPassthrough);

        $xsrfCookies = array_filter(
            $response->headers->getCookies(),
            fn($c) => $c->getName() === 'XSRF-TOKEN',
        );
        $this->assertCount(1, $xsrfCookies);
    }

    private function jsonPassthrough(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('{"data":{}}', 200, ['Content-Type' => 'application/json']);
            }
        };
    }

    private function authenticatedAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function anonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }
}
