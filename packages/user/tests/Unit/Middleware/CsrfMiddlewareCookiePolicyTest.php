<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Session\SessionCookiePolicy;

/**
 * Regression tests for issue #2149: the XSRF-TOKEN cookie must consume the
 * same resolved `session.cookie` secure/samesite policy as the session
 * cookie, instead of unconditionally tracking the request scheme.
 */
#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareCookiePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = ['_csrf_token' => bin2hex(random_bytes(32))];
    }

    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    #[Test]
    public function forced_secure_true_mints_secure_xsrf_cookie_on_plaintext_request(): void
    {
        // The production evidence in #2149: session.cookie.secure => true is
        // forced, yet a plain-HTTP request received XSRF-TOKEN without Secure.
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => true]));

        $request = Request::create('http://example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue(
            $cookie->isSecure(),
            'Forced session.cookie.secure=true must keep Secure on the XSRF-TOKEN cookie even over plaintext HTTP.',
        );
    }

    #[Test]
    public function forced_secure_true_applies_on_authenticated_json_path(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => true]));

        $request = Request::create('http://example.test/api/user/me', 'GET');
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $response = $middleware->process($request, $this->jsonPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue(
            $cookie->isSecure(),
            'The authenticated-JSON delivery path must apply the same forced-secure policy as the HTML path.',
        );
    }

    #[Test]
    public function forced_secure_false_omits_secure_on_https_request(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => false]));

        $request = Request::create('https://example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertFalse(
            $cookie->isSecure(),
            'An explicit secure=false override must win over the request scheme, matching session-cookie semantics.',
        );
    }

    #[Test]
    public function auto_secure_follows_request_scheme(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => 'auto']));

        $httpsResponse = $middleware->process(
            Request::create('https://example.test/page', 'GET'),
            $this->htmlPassthrough(),
        );
        $this->assertTrue($this->findXsrfCookie($httpsResponse)?->isSecure());

        $httpResponse = $middleware->process(
            Request::create('http://example.test/page', 'GET'),
            $this->htmlPassthrough(),
        );
        $this->assertFalse($this->findXsrfCookie($httpResponse)?->isSecure());
    }

    #[Test]
    public function configured_samesite_is_applied_to_xsrf_cookie(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['samesite' => 'Strict']));

        $request = Request::create('http://example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame(
            'strict',
            $cookie->getSameSite(),
            'A configured session.cookie.samesite must govern the XSRF-TOKEN cookie too.',
        );
    }

    #[Test]
    public function samesite_opt_out_omits_the_attribute(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['samesite' => '']));

        $request = Request::create('http://example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertNull(
            $cookie->getSameSite(),
            'The samesite opt-out (empty string) must omit the attribute, mirroring the session-cookie ini path.',
        );
    }

    #[Test]
    public function invalid_configured_samesite_falls_back_to_lax_instead_of_throwing(): void
    {
        // Pre-normalization this threw InvalidArgumentException inside
        // Symfony's Cookie::withSameSite() on every cookie-attaching
        // response, turning a samesite typo into a site-wide 500.
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['samesite' => 'Laxx']));

        $request = Request::create('http://example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame('lax', $cookie->getSameSite());
    }

    #[Test]
    public function html_403_response_applies_the_policy(): void
    {
        // The CSRF-failure branches build their response before the normal
        // unwind, so they thread the policy separately — pin it (#2149 review).
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => true]));

        $route = new Route('/form');
        $route->setOption('_render', true);

        $request = Request::create('http://example.test/form', 'POST');
        $request->attributes->set('_route_object', $route);

        $response = $middleware->process($request, $this->htmlPassthrough());

        $this->assertSame(403, $response->getStatusCode());
        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    #[Test]
    public function json_403_response_applies_the_policy_for_authenticated_session(): void
    {
        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['secure' => true]));

        $route = new Route('/api/approvals');
        $route->setOption('_csrf', true);

        $request = Request::create('http://example.test/api/approvals', 'POST', [], [], [], [], '{"decision":"approve"}');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_route_object', $route);
        $request->attributes->set('_account', $this->authenticatedAccount());
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $response = $middleware->process($request, $this->jsonPassthrough());

        $this->assertSame(403, $response->getStatusCode());
        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    #[Test]
    public function static_helper_honours_an_explicit_policy(): void
    {
        $request = Request::create('http://example.test/page', 'GET');
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        CsrfMiddleware::attachCookieIfHtml($request, $response, new SessionCookiePolicy(['secure' => true]));

        $cookie = $this->findXsrfCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    private function htmlPassthrough(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
        };
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

    private function findXsrfCookie(Response $response, string $name = 'XSRF-TOKEN'): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    #[Test]
    public function host_bound_csrf_cookie_uses_host_name_secure_root_path_and_omits_domain(): void
    {
        $_SESSION['_csrf_token'] = 'host-bound-token';

        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['host_bound' => true]));
        $request = Request::create('https://app.example.test/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());
        $cookie = $this->findXsrfCookie($response, SessionCookiePolicy::HOST_BOUND_CSRF_COOKIE_NAME);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->getDomain() === '' || $cookie->getDomain() === null);
        $this->assertSame(rawurlencode('host-bound-token'), $cookie->getValue());
        // Native-header evidence only — browser __Host- enforcement is a separate acceptance lane.
    }

    #[Test]
    public function configured_csrf_name_is_used_on_html_responses(): void
    {
        $_SESSION['_csrf_token'] = 'named-token';

        $middleware = new CsrfMiddleware(new SessionCookiePolicy(['csrf_name' => 'APP-XSRF']));
        $request = Request::create('/page', 'GET');
        $response = $middleware->process($request, $this->htmlPassthrough());
        $this->assertNotNull($this->findXsrfCookie($response, 'APP-XSRF'));
        $this->assertNull($this->findXsrfCookie($response, 'XSRF-TOKEN'));
    }
}
