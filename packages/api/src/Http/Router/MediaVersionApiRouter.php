<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Dispatches media version read endpoints (versioned-blob-media-abstraction-01KSEFTJ).
 *
 * Routes registered in BuiltinRouteRegistrar:
 *   - GET /api/media/{uuid}/versions       → MediaVersionController::index
 *   - GET /api/media/{uuid}/versions/{vid} → MediaVersionController::show
 *
 * All endpoints gated by `_authenticated` at the route level; per-version
 * access filtering is applied inside the read-model adapter (GateInterface).
 *
 * Mirrors the WorkflowGuardsApiRouter / QueueAdminApiRouter shape so the
 * pattern remains consistent across domain routers.
 *
 * Refs DIR-005 (versioned-blob-media-abstraction-01KSEFTJ).
 */
final class MediaVersionApiRouter implements DomainRouterInterface
{
    public function __construct(
        private readonly MediaVersionController $controller,
    ) {}

    public function supports(Request $request): bool
    {
        $controllerRef = $request->attributes->get('_controller', '');

        return is_string($controllerRef) && str_contains($controllerRef, 'MediaVersionController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return self::errorResponse(500, 'Internal Server Error', 'Invalid media version controller reference.');
        }

        [, $action] = explode('::', $controllerRef, 2);

        return match ($action) {
            'index' => $this->respondIndex($request),
            'show' => $this->respondShow($request),
            default => self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown media version action: %s', $action),
            ),
        };
    }

    private function respondIndex(Request $request): JsonResponse
    {
        $uuid = self::routeUuid($request);
        $payload = $this->controller->index($uuid, $request);

        return $this->jsonApiResponse($payload);
    }

    private function respondShow(Request $request): JsonResponse
    {
        $uuid = self::routeUuid($request);
        $vid = self::routeVid($request);
        $payload = $this->controller->show($uuid, $vid, $request);

        return $this->jsonApiResponse($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonApiResponse(array $payload): JsonResponse
    {
        $httpStatus = 200;
        if (isset($payload['status']) && is_int($payload['status'])) {
            $httpStatus = $payload['status'];
            unset($payload['status']);
            $payload['jsonapi'] = ['version' => '1.1'];
        }

        return new JsonResponse(
            $payload,
            $httpStatus,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    private static function routeUuid(Request $request): string
    {
        $uuid = $request->attributes->get('uuid');

        return is_scalar($uuid) ? (string) $uuid : '';
    }

    private static function routeVid(Request $request): int
    {
        $vid = $request->attributes->get('vid');

        return is_scalar($vid) ? (int) $vid : 0;
    }

    private static function errorResponse(int $httpStatus, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $httpStatus,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $httpStatus, ['Content-Type' => 'application/vnd.api+json']);
    }
}
