<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\Session\SessionCookiePolicy;

#[AsMiddleware(pipeline: 'http', priority: 20)]
final class CsrfMiddleware implements HttpMiddlewareInterface
{
    private const TOKEN_SESSION_KEY = '_csrf_token';
    private const TOKEN_FIELD_NAME = '_csrf_token';
    private const TOKEN_HEADER_NAME = 'X-CSRF-Token';
    private const XSRF_HEADER_NAME = 'X-XSRF-TOKEN';
    private const XSRF_COOKIE_NAME = 'XSRF-TOKEN';
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const CSRF_EXEMPT_CONTENT_TYPES = ['application/vnd.api+json', 'application/json'];

    private readonly SessionCookiePolicy $cookiePolicy;

    /**
     * @param SessionCookiePolicy|null $cookiePolicy Resolved `session.cookie`
     *        policy governing the XSRF-TOKEN cookie's Secure/SameSite
     *        attributes (#2149) — the same policy SessionMiddleware applies to
     *        the session cookie. Null keeps the hardened defaults
     *        (secure='auto', samesite='Lax'), which match the previous
     *        hardcoded behavior.
     */
    public function __construct(?SessionCookiePolicy $cookiePolicy = null)
    {
        $this->cookiePolicy = $cookiePolicy ?? new SessionCookiePolicy();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $this->ensureToken();

        if ($this->requiresValidation($request) && !$this->hasValidToken($request)) {
            $route = $request->attributes->get('_route_object');
            $isRenderRoute = $route instanceof Route && $route->getOption('_render') === true;

            if ($isRenderRoute) {
                $response = new Response(
                    $this->renderHtmlError(),
                    403,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                );
                self::attachCookieIfHtml($request, $response, $this->cookiePolicy);

                return $response;
            }

            $response = new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => 'CSRF token validation failed.',
                ]],
            ], 403, ['Content-Type' => 'application/vnd.api+json']);
            self::attachCookieIfAuthenticated($request, $response, $this->cookiePolicy);

            return $response;
        }

        $response = $next->handle($request);
        self::attachCookieIfHtml($request, $response, $this->cookiePolicy);
        self::attachCookieIfAuthenticated($request, $response, $this->cookiePolicy);

        return $response;
    }

    /**
     * Attach the XSRF-TOKEN cookie to a response if it is a text/html response.
     *
     * The kernel's middleware pipeline terminates in real controller dispatch,
     * so {@see process()} calls this helper while unwinding over the final
     * response. It stays public for backwards compatibility and focused tests.
     * A null $policy applies the hardened `session.cookie` defaults.
     */
    public static function attachCookieIfHtml(Request $request, Response $response, ?SessionCookiePolicy $policy = null): void
    {
        $contentType = $response->headers->get('Content-Type', '');
        $primaryType = strtolower(trim(explode(';', $contentType)[0]));
        if ($primaryType !== 'text/html') {
            return;
        }

        self::attachCookie($request, $response, $policy ?? new SessionCookiePolicy());
    }

    /**
     * Attach the XSRF-TOKEN cookie to any response for an authenticated session.
     *
     * The admin SPA boots against JSON endpoints (e.g. GET /api/user/me), never
     * receiving a text/html response from the kernel, so the HTML-only cookie
     * writer above cannot seed it with a token. Once the session is
     * authenticated the token is delivered on API responses too, with the exact
     * same cookie flags. Anonymous responses stay cookie-free.
     * A null $policy applies the hardened `session.cookie` defaults.
     */
    public static function attachCookieIfAuthenticated(Request $request, Response $response, ?SessionCookiePolicy $policy = null): void
    {
        $account = $request->attributes->get('_account');
        $session = $request->attributes->get('_session');
        if (!$account instanceof AccountInterface
            || !$account->isAuthenticated()
            || !\is_array($session)
            || !isset($session['waaseyaa_uid'])
            || $session['waaseyaa_uid'] === ''
        ) {
            return;
        }

        self::attachCookie($request, $response, $policy ?? new SessionCookiePolicy());
    }

    private static function attachCookie(Request $request, Response $response, SessionCookiePolicy $policy): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        // Idempotency: skip if cookie already present.
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::XSRF_COOKIE_NAME) {
                return;
            }
        }

        $token = $_SESSION[self::TOKEN_SESSION_KEY] ?? '';
        if ($token === '') {
            return;
        }

        // Secure/SameSite come from the resolved session.cookie policy so a
        // forced `secure => true` survives plaintext requests (#2149); the
        // policy's 'auto' defers to $request->isSecure(), which honors
        // X-Forwarded-Proto via the kernel's trusted-proxy registration.
        $cookie = Cookie::create(self::XSRF_COOKIE_NAME)
            ->withValue(rawurlencode($token))
            ->withPath('/')
            ->withSecure($policy->resolveSecure($request->isSecure()))
            ->withHttpOnly(false)
            ->withSameSite($policy->sameSite());

        $response->headers->setCookie($cookie);
    }

    /**
     * Get the current CSRF token for use in templates.
     */
    public static function token(): string
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_SESSION_KEY])) {
            $_SESSION[self::TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::TOKEN_SESSION_KEY];
    }

    /**
     * Regenerate the CSRF token (call on login/logout).
     */
    public static function regenerate(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[self::TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    private function hasValidToken(Request $request): bool
    {
        $sessionToken = $this->getToken();

        // Source 1: _csrf_token POST field (no transform)
        $fieldToken = $request->request->get(self::TOKEN_FIELD_NAME);
        if (is_string($fieldToken) && hash_equals($sessionToken, $fieldToken)) {
            return true;
        }

        // Source 2: X-CSRF-Token header (no transform)
        $headerToken = $request->headers->get(self::TOKEN_HEADER_NAME);
        if (is_string($headerToken) && hash_equals($sessionToken, $headerToken)) {
            return true;
        }

        // Source 3: X-XSRF-TOKEN header (URL-decode before comparison)
        $xsrfToken = $request->headers->get(self::XSRF_HEADER_NAME);
        if (is_string($xsrfToken) && hash_equals($sessionToken, rawurldecode($xsrfToken))) {
            return true;
        }

        return false;
    }

    private function ensureToken(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        if (!isset($_SESSION[self::TOKEN_SESSION_KEY])) {
            $_SESSION[self::TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
        }
    }

    private function getToken(): string
    {
        return $_SESSION[self::TOKEN_SESSION_KEY] ?? '';
    }

    private function requiresValidation(Request $request): bool
    {
        if (!in_array($request->getMethod(), self::STATE_CHANGING_METHODS, true)) {
            return false;
        }

        $route = $request->attributes->get('_route_object');
        $routeOption = $route instanceof Route ? $route->getOption('_csrf') : null;

        // An explicit opt-in (RouteBuilder::requireCsrf()) demands token
        // validation for every content type, including the JSON exemptions
        // below — cookie-authenticated JSON endpoints (e.g. MCP write-tier
        // approvals) are CSRF-reachable despite the non-form content type.
        if ($routeOption === true) {
            return true;
        }

        if ($routeOption === false) {
            return false;
        }

        // JSON:API requests are not vulnerable to CSRF (browsers cannot send
        // application/vnd.api+json from HTML forms), so exempt them.
        $contentType = $request->headers->get('Content-Type', '');
        foreach (self::CSRF_EXEMPT_CONTENT_TYPES as $exemptType) {
            if (str_starts_with($contentType, $exemptType)) {
                return false;
            }
        }

        return true;
    }

    private function renderHtmlError(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>403 Forbidden</title>
            <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#111827;color:#F3F4F6}
            .box{text-align:center;max-width:420px;padding:2rem}.code{font-size:4rem;font-weight:700;color:#F59E0B;margin:0}.msg{color:#9CA3AF;margin:1rem 0;line-height:1.6}
            a{color:#F59E0B;text-decoration:none}a:hover{text-decoration:underline}</style></head>
            <body><div class="box"><p class="code">403</p><h1>Invalid Security Token</h1>
            <p class="msg">Your form submission could not be verified. Please go back and try again.</p>
            <p><a href="javascript:history.back()">Go back</a></p></div></body></html>
            HTML;
    }
}
