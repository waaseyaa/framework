<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches admin OIDC-client CRUD endpoints (WP05).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   GET    /api/oidc-clients             → OidcClientController::index
 *   POST   /api/oidc-clients             → OidcClientController::create
 *   GET    /api/oidc-clients/{id}        → OidcClientController::show
 *   PATCH  /api/oidc-clients/{id}        → OidcClientController::update
 *   DELETE /api/oidc-clients/{id}        → OidcClientController::delete
 *   POST   /api/oidc-clients/{id}/regenerate-secret → OidcClientController::regenerateSecret
 *
 * All gated by `_role: admin` at the route level (NFR-001).
 *
 * Mirrors QueueAdminApiRouter (M4B WP01) shape.
 */
final class OidcClientApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly OidcClientController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'OidcClientController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid OIDC client controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);
        $id = $request->attributes->get('id');
        $idStr = is_scalar($id) ? (string) $id : '';

        return match ($action) {
            'index' => new JsonResponse(
                $this->controller->index(),
                200,
                ['Content-Type' => 'application/vnd.api+json'],
            ),
            'show' => $this->controller->show($idStr),
            'create' => $this->controller->create($request),
            'update' => $this->controller->update($idStr, $request),
            'delete' => $this->controller->delete($idStr),
            'regenerateSecret' => $this->controller->regenerateSecret($idStr),
            default => self::errorResponse(404, 'Not Found', "Unknown OIDC client action: {$action}"),
        };
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
