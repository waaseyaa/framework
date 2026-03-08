<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Routing\AccessChecker;

final class AuthorizationMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $route = $request->attributes->get('_route_object');

        if ($route === null) {
            return $next->handle($request);
        }

        $account = $request->attributes->get('_account');

        if (!$account instanceof AccountInterface) {
            error_log('[Waaseyaa] AuthorizationMiddleware: _account not set or invalid; denying access.');
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
}
