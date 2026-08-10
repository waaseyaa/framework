<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tenancy\TenancyViolationException;

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
    /**
     * Max attempts to allocate-and-insert a revision id before giving up (#1706).
     *
     * Revision ids are allocated `MAX(revision_id)+1`, which is NOT atomic: a
     * concurrent writer for the same key can read the same MAX and claim the
     * same id between our read and our INSERT. The composite PRIMARY KEY
     * (`entity_id, revision_id` — and `entity_id, langcode, revision_id` for
     * translations) is the integrity backstop: it makes a duplicate id
     * structurally impossible, so a race can only make the losing INSERT fail,
     * never silently assign the same id. This retry count turns that failure
     * into liveness — re-read MAX and try the next id — while staying bounded so
     * a genuine, persistent unique conflict still surfaces rather than spinning.
     */
    private const int MAX_REVISION_ALLOCATION_ATTEMPTS = 5;

    private readonly string $revisionTable;

    private readonly string $translationRevisionTable;

    private readonly EntityClockInterface $clock;

    /**
     * In-process per-`(entity_id, langcode)` current-revision pointer (FR-007).
     *
     * The persisted per-language tip is the MAX(revision_id) per
     * `(entity_id, langcode)` in `<entity>__translation__revision`; this driver
     * tracks the in-flight pointer for the duration of a save so the repository
     * can read the just-written revision without re-querying.
     *
     * @var array<string, array<string, int>>  entityId -> langcode -> revisionId
     */
    private array $currentLangcodePointers = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly EntityTypeInterface $entityType,
        ?EntityClockInterface $clock = null,
        private readonly ?CommunityScope $communityScope = null,
    ) {
        $this->revisionTable = $this->entityType->id() . '_revision';
        $this->translationRevisionTable = $this->entityType->id() . '__translation__revision';
        $this->clock = $clock ?? new UtcEntityClock();
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
     * @param ?int                 $author   Resolved acting account uid recorded as
     *     `revision_author` (mission revision-audit-provenance-01KTWY5V, FR-001).
     *     `0` = the anonymous account acted; `null` = no acting context (SQL NULL,
     *     never coerced to 0). Resolution happens in EntityRepository — this
     *     driver writes what it is given.
     * @return int The new revision ID.
     */
    public function writeRevision(string $entityId, array $values, ?string $log, ?string $langcode = null, ?int $author = null): int
    {
        $this->assertScopedMutationAllowed($entityId);

        if ($langcode !== null && $this->isTwoAxis()) {
            return $this->writePerLangcodeRevision($entityId, $values, $log, $langcode, $author);
        }

        return $this->writeDefaultRevision($entityId, $values, $log, $author);
    }

    /**
     * Update an existing revision row's field values in place.
     *
     * Preserves revision_created, revision_log, and revision_author
     * (immutable revision metadata — contract revision-author.md clause 6).
     *
     * @param array<string, mixed> $values Updated field values.
     */
    public function updateRevision(string $entityId, int $revisionId, array $values): void
    {
        $this->assertScopedMutationAllowed($entityId);
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $updateFields = [];
        foreach ($values as $key => $value) {
            if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'revision_author', 'is_default_revision', 'is_latest_revision'], true)) {
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
                if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'revision_author', 'is_default_revision', 'is_latest_revision'], true)) {
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
        if (!$this->isVisible($entityId)) {
            return null;
        }

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
        if (!$this->isVisible($entityId)) {
            return null;
        }

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
        if (!$this->isVisible($entityId)) {
            return [];
        }

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
        $this->assertScopedMutationAllowed($entityId);
        $db = $this->getDatabase();

        // Guard: cannot delete the default revision (invariant #8) or the
        // published revision (FR-038 extension to the published pointer,
        // #1920 WP-2 rework task 5 / review finding #6 — deleting it would
        // silently flip the entity into never-published semantics). `SELECT *`
        // (rather than a hardcoded column list) so this tolerates pre-WP-2
        // base tables that lack the `published_revision_id` column entirely.
        $baseTable = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $result = $db->query(
            'SELECT * FROM ' . $baseTable . ' WHERE ' . $idKey . ' = ?',
            [$entityId],
        );
        foreach ($result as $row) {
            $row = (array) $row;
            if ((int) ($row['revision_id'] ?? 0) === $revisionId) {
                throw new \LogicException(
                    "Cannot delete the default revision {$revisionId} for entity {$entityId}. Delete the entity instead.",
                );
            }
            $publishedRevisionId = $row['published_revision_id'] ?? null;
            if ($publishedRevisionId !== null && (int) $publishedRevisionId === $revisionId) {
                throw new \LogicException(
                    "Cannot delete the published revision {$revisionId} for entity {$entityId}. Move the published pointer first.",
                );
            }
        }

        // Guard: cannot delete the LATEST revision either (CW-v1 option-1,
        // #1920 PR-1) — under default-revision discipline the base
        // `revision_id` pointer checked above stops tracking the tip (it
        // stays equal to `published_revision_id`), so the working copy is no
        // longer covered by either guard above. Deleting it during a review
        // window would destroy an in-progress draft.
        $latestRevisionId = $this->getLatestRevisionId($entityId);
        if ($latestRevisionId !== null && $latestRevisionId === $revisionId) {
            throw new \LogicException(
                "Cannot delete the latest revision {$revisionId} for entity {$entityId}. It is the current working copy.",
            );
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
        $this->assertScopedMutationAllowed($entityId);
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
    private function writeDefaultRevision(string $entityId, array $values, ?string $log, ?int $author = null): int
    {
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        // Allocate-and-insert with a bounded retry (#1706). getNextRevisionId is
        // MAX+1, not atomic; the composite PK rejects a concurrent duplicate, and
        // we retry with a freshly re-read MAX rather than surfacing that to the
        // caller. Single-threaded, the loop runs exactly once.
        for ($attempt = 1; ; $attempt++) {
            $revisionId = $this->getNextRevisionId($entityId);

            $row = [
                'entity_id'        => $entityId,
                'revision_id'      => $revisionId,
                'revision_created' => $this->clock->now()->format('Y-m-d H:i:s'),
                'revision_log'     => $log,
                // Resolved acting account (FR-001). SQL NULL when no actor was in
                // scope; 0 if and only if the anonymous account acted.
                'revision_author'  => $author,
            ];

            foreach ($values as $key => $value) {
                // `revision_author` in $values is skipped: the explicit $author
                // parameter is authoritative (a rollback re-reads an old revision
                // row whose author must NOT leak onto the new revision).
                if ($key === $idKey || $key === 'revision_id' || $key === 'revision_author' || $key === 'is_default_revision' || $key === 'is_latest_revision') {
                    continue;
                }
                $row[$key] = $value;
            }

            $row = $this->foldData($this->revisionTable, $row);

            try {
                $db->insert($this->revisionTable)
                    ->fields(array_keys($row))
                    ->values($row)
                    ->execute();

                return $revisionId;
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent writer claimed this (entity_id, revision_id); the
                // composite PK rejected our duplicate. Re-read MAX and retry.
                if ($attempt >= self::MAX_REVISION_ALLOCATION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
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
        ?int $author = null,
    ): int {
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        // Bounded allocate-and-insert retry (#1706), same rationale as the
        // single-axis path: the per-(entity_id, langcode) MAX+1 allocation is
        // not atomic, and the composite PK (entity_id, langcode, revision_id) is
        // the integrity backstop that lets us retry the loser rather than leak a
        // unique-constraint violation to the caller.
        for ($attempt = 1; ; $attempt++) {
            $revisionId = $this->getNextLangcodeRevisionId($entityId, $langcode);

            $row = [
                'entity_id'        => $entityId,
                'langcode'         => $langcode,
                'revision_id'      => $revisionId,
                'revision_created' => $this->clock->now()->format('Y-m-d H:i:s'),
                'revision_log'     => $log,
                // Resolved acting account (FR-001) — same semantics as the
                // single-axis path: NULL = no actor, 0 = anonymous.
                'revision_author'  => $author,
            ];

            foreach ($values as $key => $value) {
                if (
                    $key === $idKey
                    || $key === 'revision_id'
                    || $key === 'langcode'
                    || $key === 'revision_author'
                    || $key === 'is_default_revision'
                    || $key === 'is_latest_revision'
                ) {
                    continue;
                }
                $row[$key] = $value;
            }

            $row = $this->foldData($this->translationRevisionTable, $row);

            try {
                $db->insert($this->translationRevisionTable)
                    ->fields(array_keys($row))
                    ->values($row)
                    ->execute();

                $this->currentLangcodePointers[$entityId][$langcode] = $revisionId;

                return $revisionId;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_REVISION_ALLOCATION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
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
     * Read one per-language revision row from `<entity>__translation__revision`.
     *
     * Counterpart to {@see readRevision()} for the translation axis. Returns the
     * merged value map (the `_data` blob decoded onto the row), or null when no
     * such `(entity_id, langcode, revision_id)` row exists.
     *
     * @return array<string, mixed>|null
     *
     * @api
     */
    public function readLangcodeRevision(string $entityId, string $langcode, int $revisionId): ?array
    {
        if (!$this->isVisible($entityId)) {
            return null;
        }

        $db = $this->getDatabase();

        $result = $db->select($this->translationRevisionTable)
            ->fields($this->translationRevisionTable)
            ->condition('entity_id', $entityId)
            ->condition('langcode', $langcode)
            ->condition('revision_id', (string) $revisionId)
            ->execute();

        foreach ($result as $row) {
            return $this->mergeData((array) $row);
        }

        return null;
    }

    /**
     * Latest (tip) revision id for one `(entity, langcode)`, or null when that
     * language has no revisions yet. Independent of other languages.
     *
     * @api
     */
    public function getLatestLangcodeRevisionId(string $entityId, string $langcode): ?int
    {
        if (!$this->isVisible($entityId)) {
            return null;
        }

        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT MAX(revision_id) AS max_rev FROM ' . $this->translationRevisionTable
            . ' WHERE entity_id = ? AND langcode = ?',
            [$entityId, $langcode],
        );

        foreach ($result as $row) {
            $row = (array) $row;
            return $row['max_rev'] !== null ? (int) $row['max_rev'] : null;
        }

        return null;
    }

    /**
     * All revision ids for one `(entity, langcode)`, ascending.
     *
     * @return int[]
     *
     * @api
     */
    public function getLangcodeRevisionIds(string $entityId, string $langcode): array
    {
        if (!$this->isVisible($entityId)) {
            return [];
        }

        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT revision_id FROM ' . $this->translationRevisionTable
            . ' WHERE entity_id = ? AND langcode = ? ORDER BY revision_id ASC',
            [$entityId, $langcode],
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) ((array) $row)['revision_id'];
        }

        return $ids;
    }

    /**
     * Langcodes that have at least one translation revision for this entity,
     * ascending. Used to enumerate the languages an entity carries.
     *
     * @return string[]
     *
     * @api
     */
    public function getLangcodesWithRevisions(string $entityId): array
    {
        if (!$this->isVisible($entityId)) {
            return [];
        }

        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT DISTINCT langcode FROM ' . $this->translationRevisionTable
            . ' WHERE entity_id = ? ORDER BY langcode ASC',
            [$entityId],
        );

        $langcodes = [];
        foreach ($result as $row) {
            $langcodes[] = (string) ((array) $row)['langcode'];
        }

        return $langcodes;
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
        if (!$this->isVisible($entityId)) {
            return null;
        }

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
        $this->assertScopedMutationAllowed($entityId);
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
        if (!$this->isVisible($entityId)) {
            return false;
        }

        return isset($this->currentLangcodePointers[$entityId][$langcode]);
    }

    /**
     * Revision tables intentionally do not duplicate the tenancy discriminator.
     * Their visibility is anchored to the entity's indexed base-table row.
     */
    private function isVisible(string $entityId): bool
    {
        if (!$this->communityScope?->isActive()) {
            return true;
        }

        return $this->baseScopeState($entityId) === 1;
    }

    public function assertEntityMutationAllowed(string $entityId): void
    {
        $this->assertScopedMutationAllowed($entityId);
    }

    public function requiresBaseAnchor(): bool
    {
        return $this->communityScope?->isActive() ?? false;
    }

    private function assertScopedMutationAllowed(string $entityId): void
    {
        if (!$this->communityScope?->isActive()) {
            return;
        }

        $active = $this->communityScope->getCommunityId();
        $state = $this->baseScopeState($entityId);
        if ($state === 1) {
            return;
        }

        throw TenancyViolationException::invisibleEntity($active, $this->entityType->id(), $entityId);
    }

    /**
     * @return int 0 when absent, 1 when visible, 2 when only foreign rows exist.
     */
    private function baseScopeState(string $entityId): int
    {
        $db = $this->getDatabase();
        $baseTable = $this->entityType->id();
        $idKey = $this->entityType->getKeys()['id'] ?? 'id';
        $active = $this->communityScope?->getCommunityId();
        $found = false;

        $result = $db->query(
            'SELECT community_id FROM ' . $baseTable . ' WHERE ' . $idKey . ' = ?',
            [$entityId],
        );
        foreach ($result as $row) {
            $found = true;
            if ((string) ((array) $row)['community_id'] === $active) {
                return 1;
            }
        }

        return $found ? 2 : 0;
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
