<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches the workflow transition endpoints (CW-v1 WP-4):
 *
 *   GET  /api/{entityType}/{id}/workflow/transitions -> WorkflowTransitionController::transitions
 *   POST /api/{entityType}/{id}/workflow/transition   -> WorkflowTransitionController::transition
 *
 * Routes are registered per entity type in `JsonApiRouteProvider`, only when
 * `TransitionService` resolves (`ApiServiceProvider::routes()` /
 * `httpDomainRouters()` both gate on `resolveOptional(TransitionService::class)`
 * — a core-only install without `waaseyaa/workflows` wired gets neither the
 * routes nor this router, so the request 404s naturally). Mirrors the
 * controller-router-resolveOptional shape of {@see WorkflowGuardsApiRouter}
 * (pattern only — that controller itself is retired WP-5 machinery).
 */
final class WorkflowTransitionApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly WorkflowTransitionController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'WorkflowTransitionController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid workflow transition controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        $entityType = self::routeAttribute($request, '_entity_type');
        $id = self::routeAttribute($request, 'id');

        return match ($action) {
            'transitions' => $this->controller->transitions($request, $entityType, $id),
            'transition' => $this->controller->transition($request, $entityType, $id),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown workflow transition action: %s', $action),
            ),
        };
    }

    private static function routeAttribute(Request $request, string $key): string
    {
        $value = $request->attributes->get($key);

        return is_scalar($value) ? (string) $value : '';
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
