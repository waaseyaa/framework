<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;

/** Request boundary for the five canonical Admin Surface operations. */
final readonly class AdminSurfaceHttpController
{
    public function __construct(private AbstractAdminSurfaceHost $host) {}

    public function session(Request $request): JsonResponse
    {
        return self::response($this->host->handleSession($request));
    }

    public function catalog(Request $request): JsonResponse
    {
        return self::response($this->host->handleCatalog($request));
    }

    public function list(Request $request, mixed $type = null): JsonResponse
    {
        return self::response($this->host->handleList($request, self::parameter($request, 'type', $type)));
    }

    public function get(Request $request, mixed $type = null, mixed $id = null): JsonResponse
    {
        return self::response($this->host->handleGet($request, self::parameter($request, 'type', $type), self::parameter($request, 'id', $id)));
    }

    public function action(Request $request, mixed $type = null, mixed $action = null): JsonResponse
    {
        return self::response($this->host->handleAction($request, self::parameter($request, 'type', $type), self::parameter($request, 'action', $action)));
    }

    /** @param array<string, mixed> $envelope */
    private static function response(array $envelope): JsonResponse
    {
        $status = $envelope['error']['status'] ?? null;
        if (($envelope['ok'] ?? null) !== false
            || !is_int($status)
            || $status < 400
            || $status > 599
        ) {
            $status = 200;
        }

        return new JsonResponse($envelope, $status);
    }

    private static function parameter(Request $request, string $name, mixed $fallback): string
    {
        $value = $request->attributes->get($name, $fallback);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid matched Admin Surface parameter.');
        }
        return $value;
    }
}
