<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

use Waaseyaa\Entity\Storage\PrimaryStorageBackend;

/**
 * A field declares `indexed: true` but its entity type's primary storage
 * backend cannot materialise an index for it (#2157).
 *
 * This exists because the failure it replaces was **silent**. Before #2157 a
 * field could declare `FieldStorage::Column` on a `sql-blob` type, pass Rule G
 * validation (which checks the declaration, not the physical shape), land in
 * the `_data` JSON blob, and be filtered over the blob forever with nobody
 * noticing the index they thought they had did not exist.
 *
 * Declaring `indexed: true` is an unambiguous statement that a physical index
 * is required, so it is now an error to declare one that cannot be created.
 * Fields that merely declare `FieldStorage::Column` on a blob-backed type keep
 * their historical behaviour, because shipped applications rely on it.
 *
 * @api
 */
final class UnmaterializableIndexException extends \RuntimeException
{
    /**
     * @param string $entityTypeId The entity type declaring the field.
     * @param string $fieldName    The field declaring `indexed: true`.
     * @param string $backendId    The backend the type actually resolved to.
     */
    public static function forField(string $entityTypeId, string $fieldName, string $backendId): self
    {
        return new self(\sprintf(
            'Entity type "%s" declares field "%s" as indexed: true, but its primary storage backend is "%s", '
            . 'which stores fields in the _data JSON blob and cannot create a column index. '
            . 'Either declare storageBackend: PrimaryStorageBackend::SQL_COLUMN (%s) on the #[ContentEntityType] '
            . 'attribute, which materialises FieldStorage::Column fields as real columns, or remove indexed: true. '
            . 'Note that removing indexed: true leaves the field queryable through the blob but NOT physically indexed.',
            $entityTypeId,
            $fieldName,
            $backendId,
            PrimaryStorageBackend::SQL_COLUMN,
        ));
    }
}
