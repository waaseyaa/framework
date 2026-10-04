<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin MCP endpoint routes (M5C WP01 T002).
 *
 * Routes registered in `BuiltinRouteRegistrar`:
 *   - GET /api/mcp/tools              → McpAdminController::tools
 *   - GET /api/mcp/tools/{name}       → McpAdminController::tool
 *   - GET /api/mcp/server-config      → McpAdminController::serverConfig
 *
 * All gated by `_role: admin` at the route level (NFR-001 — controller does
 * not re-check the role).
 *
 * Mirrors `MercureMonitorApiRouter` shape.
 */
final class McpAdminApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly McpAdminController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $ref = $request->attributes->get('_controller', '');

        return is_string($ref) && str_contains($ref, 'McpAdminController::');
    }

    public function handle(Request $request): Response
    {
        $ref = $request->attributes->get('_controller', '');
        if (!is_string($ref) || !str_contains($ref, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid McpAdmin controller reference.');
        }

        [, $action] = explode('::', $ref, 2);

        return match ($action) {
            'tools' => $this->tools($request),
            'tool' => $this->tool($request),
            'serverConfig' => $this->serverConfig($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown McpAdmin action: %s', $action),
            ),
        };
    }

    public function tools(Request $request): JsonResponse
    {
        return new JsonResponse($this->controller->tools($request), 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    public function tool(Request $request, mixed $name = null): JsonResponse
    {
        return new JsonResponse($this->controller->tool($request, (string) $request->attributes->get('name', '')), 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    public function serverConfig(Request $request): JsonResponse
    {
        return new JsonResponse($this->controller->serverConfig($request), 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]]],
            $status,
        );
    }
}
