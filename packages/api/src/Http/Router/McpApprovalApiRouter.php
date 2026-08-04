<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\McpApprovalController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches the MCP approval decision surface (#2177 F1 C1b):
 *   - GET  /api/mcp/approvals               → McpApprovalController::index
 *   - POST /api/mcp/approvals/{id}/decision → McpApprovalController::decide
 *
 * Access control (`_authenticated`, `_session ['waaseyaa_uid']`,
 * `_permission`, `_csrf`) is enforced by the middleware pipeline before this
 * router runs; the controller adds only the origin and separation-of-duties
 * gates the route layer cannot express.
 *
 * Mirrors {@see McpAdminApiRouter}'s shape.
 */
final class McpApprovalApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly McpApprovalController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $ref = $request->attributes->get('_controller', '');

        return is_string($ref) && str_contains($ref, 'McpApprovalController::');
    }

    public function handle(Request $request): Response
    {
        $ref = $request->attributes->get('_controller', '');
        if (!is_string($ref) || !str_contains($ref, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid McpApproval controller reference.');
        }

        [, $action] = explode('::', $ref, 2);

        return match ($action) {
            'index' => $this->controller->index($request),
            'decide' => $this->controller->decide($request, (string) $request->attributes->get('id', '')),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown McpApproval action: %s', $action),
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
