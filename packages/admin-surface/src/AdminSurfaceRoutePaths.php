<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use InvalidArgumentException;

/**
 * Canonical path patterns for the Admin Surface HTTP API.
 *
 * Single source for route registration and URL generation on the PHP side.
 * The admin SPA mirrors relative segments in packages/admin/app/runtime/adminSurfaceRoutes.ts.
 * @api
 */
final class AdminSurfaceRoutePaths
{
    public const PATH_SESSION = '/admin/_surface/session';

    public const PATH_CATALOG = '/admin/_surface/catalog';

    public const PATH_LIST = '/admin/_surface/{type}';

    public const PATH_GET = '/admin/_surface/{type}/{id}';

    public const PATH_ACTION = '/admin/_surface/{type}/action/{action}';

    public const PATH_PAGE_BUILDER_DEFINITIONS = '/admin/_surface/page-builder/{surface}/definitions';

    public const PATH_PAGE_BUILDER_DRAFT = '/admin/_surface/page-builder/{surface}/{id}';

    public const PATH_PAGE_BUILDER_COMMAND = '/admin/_surface/page-builder/{surface}/{id}/commands';

    public const PATH_PAGE_BUILDER_PREVIEW = '/admin/_surface/page-builder/{surface}/{id}/preview';

    /**
     * Build a concrete URL path for a named admin surface route (path only, no scheme or host).
     *
     * @param array{type?: string, id?: string, action?: string} $parameters
     */
    public static function generate(string $name, array $parameters = []): string
    {
        return match ($name) {
            'admin_surface.session' => self::PATH_SESSION,
            'admin_surface.catalog' => self::PATH_CATALOG,
            'admin_surface.list' => self::pathList(self::requireString($parameters, 'type', $name)),
            'admin_surface.get' => self::pathGet(
                self::requireString($parameters, 'type', $name),
                self::requireString($parameters, 'id', $name),
            ),
            'admin_surface.action' => self::pathAction(
                self::requireString($parameters, 'type', $name),
                self::requireString($parameters, 'action', $name),
            ),
            'admin_surface.page_builder.definitions' => self::pathPageBuilderDefinitions(
                self::requireString($parameters, 'surface', $name),
            ),
            'admin_surface.page_builder.draft' => self::pathPageBuilderDraft(
                self::requireString($parameters, 'surface', $name),
                self::requireString($parameters, 'id', $name),
            ),
            'admin_surface.page_builder.command' => self::pathPageBuilderDraft(
                self::requireString($parameters, 'surface', $name),
                self::requireString($parameters, 'id', $name),
            ) . '/commands',
            'admin_surface.page_builder.preview' => self::pathPageBuilderDraft(
                self::requireString($parameters, 'surface', $name),
                self::requireString($parameters, 'id', $name),
            ) . '/preview',
            default => throw new InvalidArgumentException(
                sprintf('Unknown admin surface route name: %s', $name),
            ),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function requireString(array $parameters, string $key, string $routeName): string
    {
        if (!isset($parameters[$key]) || !\is_string($parameters[$key]) || $parameters[$key] === '') {
            throw new InvalidArgumentException(sprintf(
                'Missing or invalid required parameter "%s" for route "%s".',
                $key,
                $routeName,
            ));
        }

        return $parameters[$key];
    }

    private static function pathList(string $type): string
    {
        return '/admin/_surface/' . rawurlencode($type);
    }

    private static function pathGet(string $type, string $id): string
    {
        return '/admin/_surface/' . rawurlencode($type) . '/' . rawurlencode($id);
    }

    private static function pathAction(string $type, string $action): string
    {
        return '/admin/_surface/' . rawurlencode($type) . '/action/' . rawurlencode($action);
    }

    private static function pathPageBuilderDefinitions(string $surface): string
    {
        return '/admin/_surface/page-builder/' . rawurlencode($surface) . '/definitions';
    }

    private static function pathPageBuilderDraft(string $surface, string $id): string
    {
        return '/admin/_surface/page-builder/' . rawurlencode($surface) . '/' . rawurlencode($id);
    }
}
