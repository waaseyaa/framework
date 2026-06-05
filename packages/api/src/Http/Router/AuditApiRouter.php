<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches the OCAP audit query endpoint (T-M, ocap-audit-log-substrate-01KSEFTF).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   - GET /api/audit/events → AuditQueryController::index
 *
 * Gated by `_role: admin` at the route level (NFR-001). The controller does
 * NOT re-check role.
 *
 * Mirrors `WorkflowGuardsApiRouter` shape: controller-router-resolveOptional
 * layout so the router is wired only when the read model is available.
 */
final class AuditApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly AuditQueryController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'AuditQueryController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid audit controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => $this->respondIndex($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown audit action: %s', $action),
            ),
        };
    }

    private function respondIndex(Request $request): JsonResponse
    {
        $payload = $this->controller->index($request);

        return new JsonResponse(
            $payload,
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            [
                'jsonapi' => ['version' => '1.1'],
                'errors'  => [[
                    'status' => (string) $status,
                    'title'  => $title,
                    'detail' => $detail,
                ]],
            ],
            $status,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }
}
