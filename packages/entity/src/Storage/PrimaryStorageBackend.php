<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Storage;

/**
 * The primary-storage-backend ids an entity type may declare (#2157).
 *
 * `packages/entity` must not depend on `packages/entity-storage`, so these
 * constants deliberately MIRROR {@see \Waaseyaa\EntityStorage\Backend\ReservedBackendIds}
 * rather than importing it. That is the same string-literal-across-layers
 * pattern the framework already uses for cross-layer attribute references.
 *
 * The mirror cannot silently drift: `AttributeSelectedColumnBackendTest`
 * asserts the two sets are identical, and that test lives in entity-storage,
 * which can see both.
 *
 * @api
 */
final class PrimaryStorageBackend
{
    /**
     * All non-key fields live in the `_data` JSON blob. The historical default,
     * and still the default when a type declares nothing.
     */
    public const string SQL_BLOB = 'sql-blob';

    /**
     * Fields declared {@see \Waaseyaa\Field\FieldStorage::Column} become real
     * database columns, and fields declared `indexed: true` additionally receive
     * a B-tree index. Required for any field a Listing filters or sorts on if
     * that facet is to be physically indexed.
     */
    public const string SQL_COLUMN = 'sql-column';

    /** @var list<string> Every id an entity type may legally declare. */
    public const array ALL = [self::SQL_BLOB, self::SQL_COLUMN];

    private function __construct() {}
}
