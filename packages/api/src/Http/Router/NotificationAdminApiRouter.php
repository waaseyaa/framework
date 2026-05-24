<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin notification-dashboard endpoints (M4C WP01).
 *
 * Routes registered in `BuiltinRouteRegistrar`:
 *   - GET  /api/notification/channels                  → NotificationController::index
 *   - POST /api/notification/channels/{type}/test      → NotificationController::test
 *
 * All gated by `_role: admin` at the route level (NFR-001 — the controller
 * does not re-check role).
 *
 * Mirrors the M4B WP01 `QueueAdminApiRouter` and WP02 `SchedulerAdminApiRouter`
 * shapes.
 */
final class NotificationAdminApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly NotificationController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'NotificationController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid notification controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => new JsonResponse(
                $this->controller->index($request),
                200,
                ['Content-Type' => 'application/vnd.api+json'],
            ),
            'test' => $this->controller->test($request, self::routeType($request)),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown notification action: %s', $action),
            ),
        };
    }

    private static function routeType(Request $request): string
    {
        $type = $request->attributes->get('type');

        return is_scalar($type) ? (string) $type : '';
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
