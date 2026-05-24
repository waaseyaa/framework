<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin workflow-guards endpoints (M4A-5 Phase 1).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   - GET /api/workflow-definitions/{workflow_id}/guards → WorkflowGuardsController::index
 *
 * All gated by `_role: admin` at the route level (NFR-001 — the controller
 * does not re-check role).
 *
 * Mirrors the M4B `QueueAdminApiRouter` shape (controller-router-resolveOptional
 * layout) so future read-only admin endpoints can be added with the same
 * pattern. Phase 2 (edit) is deferred — see follow-up M4A-5b.
 */
final class WorkflowGuardsApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly WorkflowGuardsController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'WorkflowGuardsController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid workflow guards controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => $this->respondIndex($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown workflow guards action: %s', $action),
            ),
        };
    }

    private function respondIndex(Request $request): JsonResponse
    {
        $workflowId = self::routeWorkflowId($request);
        $payload = $this->controller->index($workflowId);

        // The controller returns either {data: ...} on success or
        // {status, errors} on a 404. Honor the controller-supplied status
        // when present; default to 200 otherwise. PHPStan knows the
        // controller's typed array shape guarantees `status` is an int when
        // the key is present, so we don't double-check the type here.
        $status = 200;
        if (isset($payload['status'])) {
            $status = $payload['status'];
            unset($payload['status']);
            $payload['jsonapi'] = ['version' => '1.1'];
        }

        return new JsonResponse(
            $payload,
            $status,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    private static function routeWorkflowId(Request $request): string
    {
        $id = $request->attributes->get('workflow_id');

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
