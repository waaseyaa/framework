<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\AiObservabilityRunsController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches AI observability runs endpoints (M5B).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   - GET  /api/ai/observability/runs                  → AiObservabilityRunsController::index
 *   - GET  /api/ai/observability/runs/{uuid}            → AiObservabilityRunsController::show
 *   - POST /api/ai/observability/runs/{uuid}/replay     → AiObservabilityRunsController::replay
 *
 * All gated by `_role: admin` at the route level.
 * The replay action additionally requires the `ai.trace.replay` gate ability.
 * Controller does NOT re-check either guard (NFR-001 / DIR-004).
 *
 * Mirrors the M5A `AiObservabilityApiRouter` + M4A-5 `WorkflowGuardsApiRouter` shape.
 */
final class AiObservabilityRunsApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly AiObservabilityRunsController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'AiObservabilityRunsController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid AI observability runs controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => $this->respondIndex($request),
            'show' => $this->respondShow($request),
            'replay' => $this->respondReplay($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown AI observability runs action: %s', $action),
            ),
        };
    }

    private function respondIndex(Request $request): JsonResponse
    {
        $query = $request->query->all();
        $payload = $this->controller->index($query);

        return new JsonResponse($payload, 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    private function respondShow(Request $request): JsonResponse
    {
        $uuid = $this->routeUuid($request);
        $payload = $this->controller->show($uuid);

        $httpStatus = 200;
        if (isset($payload['status'])) {
            $httpStatus = $payload['status'];
            unset($payload['status']);
            $payload['jsonapi'] = ['version' => '1.1'];
        }

        return new JsonResponse($payload, $httpStatus, ['Content-Type' => 'application/vnd.api+json']);
    }

    private function respondReplay(Request $request): JsonResponse
    {
        $uuid = $this->routeUuid($request);
        $payload = $this->controller->replay($uuid);

        return new JsonResponse($payload, 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    private function routeUuid(Request $request): string
    {
        $uuid = $request->attributes->get('uuid');

        return is_scalar($uuid) ? (string) $uuid : '';
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
