<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;

/**
 * SQL driver for revision table I/O.
 *
 * Handles raw read/write against the {entity_table}_revision table.
 * Does not handle entity hydration or event dispatch — that's EntityRepository's job.
 *
 * ## Two-axis (M-004 / WP03)
 *
 * When the entity type is BOTH revisionable AND translatable, callers may pass
 * an optional `?string $langcode` to {@see writeRevision()}, which routes the
 * write through the per-`(tid, langcode)` translation-revision path. The
 * single-axis path is preserved unchanged when `$langcode === null`
 * (regression gate, FR-009..FR-011).
 *
 * Per-`(entity_id, langcode)` current-revision pointer tracking is owned by
 * this driver via the in-process map exposed through
 * {@see currentLangcodeRevision()} / {@see setCurrentLangcodeRevision()}.
 * Other-language pointers are NEVER touched by a per-langcode write
 * (FR-010 — invariant verified in `RevisionableStorageDriverTwoAxisTest`).
 * @api
 */
final class RevisionableStorageDriver
{
    private readonly string $revisionTable;

    private readonly string $translationRevisionTable;

    /**
     * In-process per-`(entity_id, langcode)` current-revision pointer (FR-007).
     *
     * Persistence of this pointer in `<entity>__translation` is owned by
     * higher-level storage classes (RevisionableSqlBlobStorage /
     * RevisionableSqlColumnStorage); this driver tracks the in-flight pointer
     * for the duration of a save so the coordinator can update other tables
     * without re-querying.
     *
     * @var array<string, array<string, int>>  entityId -> langcode -> revisionId
     */
    private array $currentLangcodePointers = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly EntityTypeInterface $entityType,
    ) {
        $this->revisionTable = $this->entityType->id() . '_revision';
        $this->translationRevisionTable = $this->entityType->id() . '__translation__revision';
    }

    /**
     * Write a new revision row.
     *
     * When `$langcode` is non-null AND the entity type is two-axis (revisionable
     * + translatable), the write is dispatched to the per-`(tid, langcode)`
     * translation-revision path (FR-007, FR-009). The per-language current
     * pointer is updated for `(entityId, langcode)`; other-language pointers
     * are untouched (FR-010).
     *
     * When `$langcode` is null OR the entity type is single-axis, the single-
     * axis path is preserved unchanged (M-006 regression gate).
     *
     * @param array<string, mixed> $values   Field values to snapshot.
     * @param ?string              $langcode Optional per-langcode pin. Two-axis only.
     * @return int The new revision ID.
     */
    public function writeRevision(string $entityId, array $values, ?string $log, ?string $langcode = null): int
    {
        if ($langcode !== null && $this->isTwoAxis()) {
            return $this->writePerLangcodeRevision($entityId, $values, $log, $langcode);
        }

        return $this->writeDefaultRevision($entityId, $values, $log);
    }

    /**
     * Update an existing revision row's field values in place.
     *
     * Preserves revision_created and revision_log (immutable metadata).
     *
     * @param array<string, mixed> $values Updated field values.
     */
    public function updateRevision(string $entityId, int $revisionId, array $values): void
    {
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $updateFields = [];
        foreach ($values as $key => $value) {
            if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'is_default_revision', 'is_latest_revision'], true)) {
                continue;
            }
            $updateFields[$key] = $value;
        }

        if ($updateFields === []) {
            return;
        }

        // Route changed fields into real revision-table columns vs the `_data`
        // blob, preserving existing `_data` keys this update does not touch.
        $schema = $db->schema();
        $columnUpdates = [];
        $dataChanges = [];
        foreach ($updateFields as $key => $value) {
            if ($schema->fieldExists($this->revisionTable, $key)) {
                $columnUpdates[$key] = $value;
            } else {
                $dataChanges[$key] = $value;
            }
        }
        if ($dataChanges !== [] && $schema->fieldExists($this->revisionTable, '_data')) {
            $merged = $this->readRevision($entityId, $revisionId) ?? [];
            foreach ($dataChanges as $key => $value) {
                $merged[$key] = $value;
            }
            $extra = [];
            foreach ($merged as $key => $value) {
                if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'is_default_revision', 'is_latest_revision'], true)) {
                    continue;
                }
                if ($schema->fieldExists($this->revisionTable, $key)) {
                    continue;
                }
                $extra[$key] = $value;
            }
            $columnUpdates['_data'] = json_encode($extra, \JSON_THROW_ON_ERROR);
        }

        if ($columnUpdates === []) {
            return;
        }

        $db->update($this->revisionTable)
            ->fields($columnUpdates)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Read a specific revision row.
     *
     * @return array<string, mixed>|null
     */
    public function readRevision(string $entityId, int $revisionId): ?array
    {
        $db = $this->getDatabase();

        $result = $db->select($this->revisionTable)
            ->fields($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();

        foreach ($result as $row) {
            return $this->mergeData((array) $row);
        }

        return null;
    }

    /**
     * Read multiple revision rows for an entity.
     *
     * @param int[] $revisionIds
     * @return array<int, array<string, mixed>>
     */
    public function readMultipleRevisions(string $entityId, array $revisionIds): array
    {
        $rows = [];
        foreach ($revisionIds as $revId) {
            $row = $this->readRevision($entityId, $revId);
            if ($row !== null) {
                $rows[$revId] = $row;
            }
        }

        return $rows;
    }

    public function getLatestRevisionId(string $entityId): ?int
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT MAX(revision_id) as max_rev FROM ' . $this->revisionTable . ' WHERE entity_id = ?',
            [$entityId],
        );

        foreach ($result as $row) {
            $row = (array) $row;
            return $row['max_rev'] !== null ? (int) $row['max_rev'] : null;
        }

        return null;
    }

    /**
     * @return int[] Revision IDs in ascending order.
     */
    public function getRevisionIds(string $entityId): array
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT revision_id FROM ' . $this->revisionTable . ' WHERE entity_id = ? ORDER BY revision_id ASC',
            [$entityId],
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) ((array) $row)['revision_id'];
        }

        return $ids;
    }

    public function deleteRevision(string $entityId, int $revisionId): void
    {
        $db = $this->getDatabase();

        // Guard: cannot delete the default revision (invariant #8).
        $baseTable = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $result = $db->query(
            'SELECT revision_id FROM ' . $baseTable . ' WHERE ' . $idKey . ' = ?',
            [$entityId],
        );
        foreach ($result as $row) {
            $row = (array) $row;
            if ((int) ($row['revision_id'] ?? 0) === $revisionId) {
                throw new \LogicException(
                    "Cannot delete the default revision {$revisionId} for entity {$entityId}. Delete the entity instead.",
                );
            }
        }

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Delete all revisions for an entity.
     */
    public function deleteAllRevisions(string $entityId): void
    {
        $db = $this->getDatabase();

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->execute();
    }

    private function getNextRevisionId(string $entityId): int
    {
        $latest = $this->getLatestRevisionId($entityId);

        return ($latest ?? 0) + 1;
    }

    /**
     * Whether the entity type for this driver participates in the two-axis
     * (revisionable + translatable) storage shape.
     *
     * The base interface
     * {@see \Waaseyaa\Entity\EntityTypeInterface} exposes both
     * {@see EntityTypeInterface::isRevisionable()} and
     * {@see EntityTypeInterface::isTranslatable()}; this driver is only
     * instantiated for revisionable types, so the additional translatable
     * check is what flips into the two-axis path.
     */
    private function isTwoAxis(): bool
    {
        return $this->entityType->isRevisionable() && $this->entityType->isTranslatable();
    }

    /**
     * Single-axis (or two-axis default-langcode) revision write.
     *
     * Mirrors the M-006 behaviour byte-for-byte so the regression gate at the
     * top of this class (R-A: single-axis preserved) holds. Extracted so the
     * two-axis branch can be reasoned about in isolation (FR-011: non-
     * translatable mutations still allocate a default-langcode revision via
     * this path).
     *
     * @param array<string, mixed> $values
     */
    private function writeDefaultRevision(string $entityId, array $values, ?string $log): int
    {
        $db = $this->getDatabase();

        $revisionId = $this->getNextRevisionId($entityId);

        $row = [
            'entity_id'        => $entityId,
            'revision_id'      => $revisionId,
            'revision_created' => date('Y-m-d H:i:s'),
            'revision_log'     => $log,
        ];

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        foreach ($values as $key => $value) {
            if ($key === $idKey || $key === 'revision_id' || $key === 'is_default_revision' || $key === 'is_latest_revision') {
                continue;
            }
            $row[$key] = $value;
        }

        $row = $this->foldData($this->revisionTable, $row);
        $db->insert($this->revisionTable)
            ->fields(array_keys($row))
            ->values($row)
            ->execute();

        return $revisionId;
    }

    /**
     * Per-`(entity_id, langcode)` translation-revision write (FR-007, FR-009).
     *
     * Allocates an independent revision id per `(entity_id, langcode)` pair —
     * saving a French translation does not advance the English sequence
     * (FR-010 invariant: other-language pointers unchanged).
     *
     * Updates {@see $currentLangcodePointers} so the coordinator can read the
     * just-written revision id for the per-`(entity, langcode)` pointer write
     * in the same transaction without an extra `MAX()` query.
     *
     * @param array<string, mixed> $values
     */
    private function writePerLangcodeRevision(
        string $entityId,
        array $values,
        ?string $log,
        string $langcode,
    ): int {
        $db = $this->getDatabase();

        $revisionId = $this->getNextLangcodeRevisionId($entityId, $langcode);

        $row = [
            'entity_id'        => $entityId,
            'langcode'         => $langcode,
            'revision_id'      => $revisionId,
            'revision_created' => date('Y-m-d H:i:s'),
            'revision_log'     => $log,
        ];

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        foreach ($values as $key => $value) {
            if (
                $key === $idKey
                || $key === 'revision_id'
                || $key === 'langcode'
                || $key === 'is_default_revision'
                || $key === 'is_latest_revision'
            ) {
                continue;
            }
            $row[$key] = $value;
        }

        $row = $this->foldData($this->translationRevisionTable, $row);
        $db->insert($this->translationRevisionTable)
            ->fields(array_keys($row))
            ->values($row)
            ->execute();

        $this->currentLangcodePointers[$entityId][$langcode] = $revisionId;

        return $revisionId;
    }

    /**
     * Independent per-`(entity, langcode)` monotonic id allocator.
     *
     * Reads `MAX(revision_id)` for the pair (NOT for the entity overall) so
     * the en/oj sequences advance independently — saving French does not push
     * English forward (FR-007, FR-010).
     */
    private function getNextLangcodeRevisionId(string $entityId, string $langcode): int
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT MAX(revision_id) AS max_rev FROM ' . $this->translationRevisionTable
            . ' WHERE entity_id = ? AND langcode = ?',
            [$entityId, $langcode],
        );

        foreach ($result as $row) {
            $row = (array) $row;
            return ((int) ($row['max_rev'] ?? 0)) + 1;
        }

        return 1;
    }

    /**
     * Read the in-process per-`(entity, langcode)` current-revision pointer
     * tracked since the last per-langcode write in this driver instance.
     *
     * Returns `null` when no per-langcode write has occurred (the coordinator
     * MUST fall back to the persisted pointer in `<entity>__translation`).
     *
     * @api
     */
    public function currentLangcodeRevision(string $entityId, string $langcode): ?int
    {
        return $this->currentLangcodePointers[$entityId][$langcode] ?? null;
    }

    /**
     * Seed the in-process per-`(entity, langcode)` pointer without writing.
     *
     * Used by the coordinator when it has loaded the persisted pointer from
     * `<entity>__translation` and wants subsequent reads in the same save
     * transaction to be cache-coherent with the in-process map.
     *
     * @api
     */
    public function setCurrentLangcodeRevision(string $entityId, string $langcode, int $revisionId): void
    {
        $this->currentLangcodePointers[$entityId][$langcode] = $revisionId;
    }

    /**
     * Whether the driver currently tracks an in-process per-language pointer
     * for `(entityId, langcode)`. Diagnostic; not on the stable surface.
     *
     * @internal
     */
    public function hasCurrentLangcodeRevision(string $entityId, string $langcode): bool
    {
        return isset($this->currentLangcodePointers[$entityId][$langcode]);
    }

    private function getDatabase(): DatabaseInterface
    {
        return $this->connectionResolver->connection();
    }

    /**
     * Split a revision row into the real columns of $table plus a `_data` JSON
     * blob for everything else.
     *
     * Revision tables (like base tables) materialise only system-key columns
     * (id/uuid/bundle/label/langcode + revision bookkeeping) and a `_data`
     * column. A revisionable sql-blob entity carries arbitrary fields (folder,
     * source_uri, ...) that have no dedicated column, so they must be folded
     * into `_data` exactly as {@see SqlStorageDriver} does for the base table.
     * Without this, writing a revision of such an entity fails with
     * "no column named ...". Inverse of {@see self::mergeData()}.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function foldData(string $table, array $row): array
    {
        $schema = $this->getDatabase()->schema();
        $hasDataColumn = $schema->fieldExists($table, '_data');

        $columns = [];
        $extraData = [];
        foreach ($row as $key => $value) {
            if ($key === '_data') {
                if (is_string($value) && $value !== '') {
                    try {
                        $decoded = json_decode($value, associative: true, flags: \JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $extraData = $decoded + $extraData;
                        }
                    } catch (\JsonException) {
                        // ignore malformed pre-built blob
                    }
                } elseif (is_array($value)) {
                    $extraData = $value + $extraData;
                }
                continue;
            }

            if ($schema->fieldExists($table, $key)) {
                $columns[$key] = $value;
            } else {
                $extraData[$key] = $value;
            }
        }

        if ($hasDataColumn) {
            $columns['_data'] = json_encode($extraData, \JSON_THROW_ON_ERROR);
        }

        return $columns;
    }

    /**
     * Decode the `_data` JSON blob on a revision row and merge its keys back
     * onto the row. Inverse of {@see self::foldData()}; applied at every
     * revision read so callers see a flat value map.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeData(array $row): array
    {
        if (!array_key_exists('_data', $row)) {
            return $row;
        }

        $raw = $row['_data'];
        unset($row['_data']);

        if (!is_string($raw) || $raw === '') {
            return $row;
        }

        try {
            $extra = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $row;
        }

        // Column values win over `_data` on key collision.
        return is_array($extra) ? $row + $extra : $row;
    }
}
