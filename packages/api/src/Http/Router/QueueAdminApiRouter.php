<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin queue-dashboard endpoints (M4B WP01).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   - GET  /api/queue/jobs           → QueueController::index
 *   - POST /api/queue/jobs/{id}/retry   → QueueController::retry
 *   - POST /api/queue/jobs/{id}/discard → QueueController::discard
 *
 * All gated by `_role: admin` at the route level (NFR-001 — the controller
 * does not re-check role).
 *
 * Mirrors the M4A-1 `WorkflowDefinitionsApiRouter` shape.
 */
final class QueueAdminApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly QueueController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'QueueController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid queue controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => $this->index($request),
            'retry' => $this->retry($request),
            'discard' => $this->discard($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown queue action: %s', $action),
            ),
        };
    }

    /** Explicit HTTP terminals share the legacy request/response adaptation. */
    public function index(Request $request): Response
    {
        return new JsonResponse(
            $this->controller->index($request),
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    public function retry(Request $request, mixed $id = null): Response
    {
        // Callable dispatch forwards named route parameters; the matched
        // request retains the legacy authority for scalar ID normalization.
        return $this->controller->retry(self::routeId($request));
    }

    public function discard(Request $request, mixed $id = null): Response
    {
        return $this->controller->discard(self::routeId($request));
    }

    private static function routeId(Request $request): string
    {
        $id = $request->attributes->get('id');

        return is_scalar($id) ? (string) $id : '';
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
