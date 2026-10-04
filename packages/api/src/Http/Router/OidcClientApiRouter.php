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
        return match ($action) {
            'index' => $this->index($request),
            'show' => $this->show($request),
            'create' => $this->create($request),
            'update' => $this->update($request),
            'delete' => $this->delete($request),
            'regenerateSecret' => $this->regenerateSecret($request),
            default => self::errorResponse(404, 'Not Found', "Unknown OIDC client action: {$action}"),
        };
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse($this->controller->index(), 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    public function show(Request $request, mixed $id = null): Response
    {
        return $this->controller->show(self::requestId($request));
    }

    public function create(Request $request): Response
    {
        return $this->controller->create($request);
    }

    public function update(Request $request, mixed $id = null): Response
    {
        return $this->controller->update(self::requestId($request), $request);
    }

    public function delete(Request $request, mixed $id = null): Response
    {
        return $this->controller->delete(self::requestId($request), $request);
    }

    public function regenerateSecret(Request $request, mixed $id = null): Response
    {
        return $this->controller->regenerateSecret(self::requestId($request));
    }

    private static function requestId(Request $request): string
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
