<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use InvalidArgumentException;
use Waaseyaa\AdminSurface\Query\SurfaceFieldName;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;

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
     * A list deep link carrying schema-declared filter controls.
     *
     * This owns only the SPA query encoding. Generating the URL does not make
     * an undeclared field filterable and grants no list or field access; the
     * existing list metadata, query policy, and entity access gates remain
     * authoritative when the destination is opened.
     *
     * Field names must satisfy the canonical {@see SurfaceFieldName} grammar
     * that `ListMetadata` enforces, so a generated key cannot carry a bracket,
     * a space, or a control character into the query shape. Operators must name
     * a {@see SurfaceFilterOperator} case and are emitted in canonical form,
     * because the list restores a control only when the serialized operator
     * matches the one its metadata declares.
     *
     * Runtime validation deliberately accepts an untrusted array boundary and
     * narrows it to `field => {operator, value}` before constructing a query.
     *
     * @param non-empty-string      $entityType
     * @param array<mixed, mixed>   $filters
     */
    public static function filteredList(string $entityType, array $filters): string
    {
        if ($filters === []) {
            throw new InvalidArgumentException('AdminDestinationPaths: filters must not be empty.');
        }

        /** @var array<string, array{operator: string, value: string}> $normalized */
        $normalized = [];
        foreach ($filters as $field => $filter) {
            if (!SurfaceFieldName::isValid($field)) {
                throw new InvalidArgumentException(sprintf(
                    'AdminDestinationPaths: filter field must match %s; got "%s".',
                    SurfaceFieldName::PATTERN,
                    is_string($field) ? $field : get_debug_type($field),
                ));
            }
            if (!is_array($filter)
                || array_diff(array_keys($filter), ['operator', 'value']) !== []
                || count($filter) !== 2
                || !is_string($filter['operator'] ?? null)
                || $filter['operator'] === ''
                || !is_string($filter['value'] ?? null)
                || $filter['value'] === ''
            ) {
                throw new InvalidArgumentException(sprintf(
                    'AdminDestinationPaths: filter "%s" must contain only non-empty string operator and value members.',
                    $field,
                ));
            }
            $operator = SurfaceFilterOperator::fromString($filter['operator']);
            if ($operator === null) {
                throw new InvalidArgumentException(sprintf(
                    'AdminDestinationPaths: filter "%s" names no canonical operator: "%s".',
                    $field,
                    $filter['operator'],
                ));
            }
            $normalized[$field] = ['operator' => $operator->value, 'value' => $filter['value']];
        }
        ksort($normalized, SORT_STRING);

        $query = [];
        foreach ($normalized as $field => $filter) {
            $query[sprintf('filter[%s][operator]', $field)] = $filter['operator'];
            $query[sprintf('filter[%s][value]', $field)] = $filter['value'];
        }

        return self::typePath($entityType) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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

    /**
     * The shell-free create editor, optionally scoped to one bundle.
     *
     * @param non-empty-string $entityType
     */
    public static function embeddedCreate(string $entityType, ?string $bundle = null): string
    {
        return self::withBundle(self::embeddedEntityTypePath($entityType) . '/create', $bundle);
    }

    /**
     * The shell-free editor for one existing record.
     *
     * `create` is a reserved route identity and therefore cannot ambiguously
     * name an existing record through this generator.
     *
     * @param non-empty-string $entityType
     * @param non-empty-string $id
     */
    public static function embeddedEdit(string $entityType, string $id): string
    {
        $id = self::require($id, 'id');
        if ($id === 'create') {
            throw new InvalidArgumentException('AdminDestinationPaths: id "create" is reserved by the embedded create route.');
        }

        return self::embeddedEntityTypePath($entityType) . '/' . rawurlencode($id);
    }

    /**
     * The shell-free page builder for one registered surface record.
     *
     * @param non-empty-string $surface
     * @param non-empty-string $id
     */
    public static function embeddedPageBuilder(string $surface, string $id): string
    {
        return self::MOUNT . '/page-builder-embed/'
            . rawurlencode(self::require($surface, 'surface')) . '/'
            . rawurlencode(self::require($id, 'id'));
    }

    private static function typePath(string $entityType): string
    {
        return self::MOUNT . '/' . rawurlencode(self::require($entityType, 'entityType'));
    }

    private static function embeddedEntityTypePath(string $entityType): string
    {
        return self::MOUNT . '/entity-editor-embed/' . rawurlencode(self::require($entityType, 'entityType'));
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
