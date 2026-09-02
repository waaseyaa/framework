<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The primary-storage-backend a blueprint entity may declare (#2785).
 *
 * Case values MUST equal {@see \Waaseyaa\Entity\Storage\PrimaryStorageBackend::SQL_BLOB}
 * and `::SQL_COLUMN`. `waaseyaa/site-contract` is Layer 0 and must not import
 * `waaseyaa/entity` (Layer 1), so the equality is proven by
 * `tests/Architecture/ApplicationBlueprintVocabularyTest.php` against the
 * live string literals rather than by a shared import — the same
 * mirror-without-import pattern `PrimaryStorageBackend` itself documents.
 *
 * @api
 */
enum BlueprintStorage: string
{
    case SqlBlob = 'sql-blob';
    case SqlColumn = 'sql-column';
}
