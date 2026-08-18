<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use InvalidArgumentException;

/**
 * Canonical path patterns for the Admin SPA's own pages.
 *
 * The companion to {@see AdminSurfaceRoutePaths}, which is the single source
 * for the `_surface` HTTP API. This class is the single source for the pages an
 * operator actually opens — the destinations a consumer links to from outside
 * the SPA.
 *
 * Without it, every consumer concatenates its own strings and nothing in the
 * framework fails when the SPA's routes move. Each destination here
 * corresponds to a page in the SPA's file-based router
 * (`packages/admin/app/pages/[entityType]/`), and that correspondence is
 * pinned by AdminDestinationPathsTest.
 *
 * Generating a destination is not an access decision. These methods produce
 * URLs; deciding whether a principal may be offered one stays with the caller,
 * exactly as it is today.
 *
 * @api
 */
final class AdminDestinationPaths
{
    /** Mount point of the admin SPA — see the `admin_spa` route. */
    public const MOUNT = '/admin';

    /**
     * Query parameter carrying a bundle scope (#2418).
     *
     * One contract shared with the SPA: `pages/[entityType]/create.vue`
     * preselects the bundle, `pages/[entityType]/index.vue` applies it as a
     * list filter. Both degrade to the unscoped view when the value is
     * unknown, malformed, or not permitted, so a stale link never dead-ends.
     */
    public const QUERY_BUNDLE = 'bundle';

    private function __construct() {}

    /**
     * The list of an entity type, optionally scoped to one bundle.
     *
     * @param non-empty-string $entityType
     */
    public static function list(string $entityType, ?string $bundle = null): string
    {
        return self::withBundle(self::typePath($entityType), $bundle);
    }

    /**
     * The create form for an entity type, optionally preselecting one bundle.
     *
     * @param non-empty-string $entityType
     */
    public static function create(string $entityType, ?string $bundle = null): string
    {
        return self::withBundle(self::typePath($entityType) . '/create', $bundle);
    }

    /**
     * The record editor for one entity.
     *
     * @param non-empty-string $entityType
     * @param non-empty-string $id
     */
    public static function edit(string $entityType, string $id): string
    {
        return self::typePath($entityType) . '/' . rawurlencode(self::require($id, 'id'));
    }

    /**
     * One record's own history surface (#2419).
     *
     * Answers for the record, not for the entity type — unlike
     * {@see self::pipeline()}. Pointing a History affordance at the pipeline or
     * at the record editor promises something neither delivers, which is why
     * the affordance was withheld downstream until this destination existed.
     *
     * Generating the URL grants nothing: the surface is gated server-side by
     * the record's own view access, and a principal who may not view the record
     * gets no history rather than an empty one.
     *
     * @param non-empty-string $entityType
     * @param non-empty-string $id
     */
    public static function history(string $entityType, string $id): string
    {
        return self::edit($entityType, $id) . '/history';
    }

    /**
     * The type-wide editorial workflow pipeline.
     *
     * Answers for the entity type, not for one record. The per-record
     * equivalent is {@see self::history()}.
     *
     * @param non-empty-string $entityType
     */
    public static function pipeline(string $entityType): string
    {
        return self::typePath($entityType) . '/pipeline';
    }

    private static function typePath(string $entityType): string
    {
        return self::MOUNT . '/' . rawurlencode(self::require($entityType, 'entityType'));
    }

    /**
     * An empty bundle is the same statement as no bundle. Emitting `?bundle=`
     * would hand the SPA a value it must then treat as absent.
     */
    private static function withBundle(string $path, ?string $bundle): string
    {
        if ($bundle === null || $bundle === '') {
            return $path;
        }

        return $path . '?' . http_build_query([self::QUERY_BUNDLE => $bundle], '', '&', PHP_QUERY_RFC3986);
    }

    private static function require(string $value, string $name): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('AdminDestinationPaths: %s must not be empty.', $name));
        }

        return $value;
    }
}
