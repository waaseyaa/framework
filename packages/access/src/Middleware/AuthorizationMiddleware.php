<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Access\AccessChecker;

#[AsMiddleware(pipeline: 'http', priority: 10)]
final class AuthorizationMiddleware implements HttpMiddlewareInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AccessChecker $accessChecker,
        private readonly ?ErrorPageRendererInterface $errorPageRenderer = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $route = $request->attributes->get('_route_object');

        if ($route === null) {
            return $next->handle($request);
        }

        $account = $request->attributes->get('_account');
        $isRenderRoute = $this->isRenderRoute($route);

        if (!$account instanceof AccountInterface) {
            $this->logger->warning('AuthorizationMiddleware: _account not set or invalid; denying access.');

            if ($isRenderRoute) {
                return $this->renderError(403, 'Forbidden', 'No authenticated account available.', $request);
            }

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => 'No authenticated account available.',
                ]],
            ], 403, ['Content-Type' => 'application/vnd.api+json']);
        }

        $result = $this->accessChecker->check($route, $account);

        if ($result->isUnauthenticated()) {
            if ($isRenderRoute) {
                $loginUrl = '/login?redirect=' . urlencode($request->getPathInfo());
                return new RedirectResponse($loginUrl, 302);
            }

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '401',
                    'title' => 'Unauthorized',
                    'detail' => $result->reason,
                ]],
            ], 401, [
                'Content-Type' => 'application/vnd.api+json',
                'WWW-Authenticate' => 'Bearer realm="Waaseyaa API"',
            ]);
        }

        if ($result->isForbidden()) {
            if ($isRenderRoute) {
                return $this->renderError(403, 'Forbidden', $result->reason ?? 'You do not have permission to access this page.', $request);
            }

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => $result->reason,
                ]],
            ], 403, ['Content-Type' => 'application/vnd.api+json']);
        }

        return $next->handle($request);
    }

    private function isRenderRoute(Route $route): bool
    {
        return $route->getOption('_render') === true;
    }

    private function renderError(int $statusCode, string $title, string $detail, Request $request): Response
    {
        if ($this->errorPageRenderer !== null) {
            $response = $this->errorPageRenderer->render($statusCode, $title, $detail, $request);
            if ($response !== null) {
                return $response;
            }
        }

        return $this->renderHtmlError($statusCode, $title, $detail, $request);
    }

    private function renderHtmlError(int $statusCode, string $title, string $detail, Request $request): Response
    {
        $loginLink = $statusCode === 403
            ? sprintf('<p><a href="/login?redirect=%s">Sign in</a> with a different account.</p>', urlencode($request->getPathInfo()))
            : '';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$statusCode} {$title}</title>
        <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#111827;color:#F3F4F6}
        .box{text-align:center;max-width:420px;padding:2rem}.code{font-size:4rem;font-weight:700;color:#F59E0B;margin:0}.msg{color:#9CA3AF;margin:1rem 0;line-height:1.6}
        a{color:#F59E0B;text-decoration:none}a:hover{text-decoration:underline}</style></head>
        <body><div class="box"><p class="code">{$statusCode}</p><h1>{$title}</h1><p class="msg">{$detail}</p>{$loginLink}</div></body></html>
        HTML;

        return new Response($html, $statusCode, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
