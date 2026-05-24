<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin scheduler-dashboard endpoints (M4B WP02).
 *
 * Routes registered in `BuiltinRouteRegistrar`:
 *   - GET  /api/scheduler/tasks                  → SchedulerController::index
 *   - POST /api/scheduler/tasks/{name}/trigger   → SchedulerController::trigger
 *
 * All gated by `_role: admin` at the route level (NFR-001 — the controller
 * does not re-check role).
 *
 * Mirrors the M4B WP01 `QueueAdminApiRouter` shape.
 */
final class SchedulerAdminApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly SchedulerController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'SchedulerController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid scheduler controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => new JsonResponse(
                $this->controller->index(),
                200,
                ['Content-Type' => 'application/vnd.api+json'],
            ),
            'trigger' => $this->controller->trigger(self::routeName($request)),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown scheduler action: %s', $action),
            ),
        };
    }

    private static function routeName(Request $request): string
    {
        $name = $request->attributes->get('name');

        return is_scalar($name) ? (string) $name : '';
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
