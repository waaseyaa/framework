<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin Mercure monitor endpoints (M5D WP01).
 *
 * Routes registered in `BuiltinRouteRegistrar`:
 *   - GET /api/mercure/channels     → MercureMonitorController::channels
 *   - GET /api/mercure/events       → MercureMonitorController::events  (SSE)
 *   - GET /api/mercure/subscribers  → MercureMonitorController::subscribers
 *
 * All gated by `_role: admin` at the route level (NFR-001 — controller does
 * not re-check the role).
 *
 * Mirrors `NotificationAdminApiRouter` shape.
 */
final class MercureMonitorApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly MercureMonitorController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $ref = $request->attributes->get('_controller', '');

        return is_string($ref) && str_contains($ref, 'MercureMonitorController::');
    }

    public function handle(Request $request): Response
    {
        $ref = $request->attributes->get('_controller', '');
        if (!is_string($ref) || !str_contains($ref, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid MercureMonitor controller reference.');
        }

        [, $action] = explode('::', $ref, 2);

        return match ($action) {
            'channels' => new JsonResponse(
                $this->controller->channels($request),
                200,
                ['Content-Type' => 'application/vnd.api+json'],
            ),
            'events' => $this->controller->events($request),
            'subscribers' => new JsonResponse(
                $this->controller->subscribers($request),
                200,
                ['Content-Type' => 'application/vnd.api+json'],
            ),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown Mercure monitor action: %s', $action),
            ),
        };
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]]],
            $status,
        );
    }
}
