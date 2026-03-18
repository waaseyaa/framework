<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

#[AsMiddleware(pipeline: 'http', priority: 20)]
final class CsrfMiddleware implements HttpMiddlewareInterface
{
    private const TOKEN_SESSION_KEY = '_csrf_token';
    private const TOKEN_FIELD_NAME = '_csrf_token';
    private const TOKEN_HEADER_NAME = 'X-CSRF-Token';
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const CSRF_EXEMPT_CONTENT_TYPES = ['application/vnd.api+json', 'application/json'];

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $this->ensureToken();

        if (!$this->requiresValidation($request)) {
            return $next->handle($request);
        }

        $submittedToken = $request->request->get(self::TOKEN_FIELD_NAME)
            ?? $request->headers->get(self::TOKEN_HEADER_NAME);

        if (!is_string($submittedToken) || !hash_equals($this->getToken(), $submittedToken)) {
            $route = $request->attributes->get('_route_object');
            $isRenderRoute = $route instanceof Route && $route->getOption('_render') === true;

            if ($isRenderRoute) {
                return new Response(
                    $this->renderHtmlError(),
                    403,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                );
            }

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => 'CSRF token validation failed.',
                ]],
            ], 403, ['Content-Type' => 'application/vnd.api+json']);
        }

        return $next->handle($request);
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

        // JSON:API requests are not vulnerable to CSRF (browsers cannot send
        // application/vnd.api+json from HTML forms), so exempt them.
        $contentType = $request->headers->get('Content-Type', '');
        foreach (self::CSRF_EXEMPT_CONTENT_TYPES as $exemptType) {
            if (str_starts_with($contentType, $exemptType)) {
                return false;
            }
        }

        $route = $request->attributes->get('_route_object');
        if ($route instanceof Route && $route->getOption('_csrf') === false) {
            return false;
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
