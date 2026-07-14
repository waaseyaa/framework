<?php

declare(strict_types=1);

namespace Waaseyaa\Migration;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\Plugin\WriteResult;

/**
 * Stable-surface repository for the `migration_id_map` table (spec §8.1).
 *
 * The id-map is a supporting (non-entity) table — direct
 * {@see DatabaseInterface} access is the canonical path per
 * `.claude/rules/entity-storage-invariant.md`. Going through
 * `EntityRepository` would buy nothing here (no event dispatch needs, no
 * hydration semantics).
 *
 * ### Transactional contract (FR-029)
 *
 * `upsert()` and `delete()` do NOT open their own transactions. Callers —
 * specifically `EntityDestination::write()` (WP05) — are expected to wrap
 * the destination-entity save and the matching id-map mutation in a single
 * transaction so the two writes are atomic. Use {@see transactional()} as
 * the helper for that pattern.
 *
 * ### Idempotency (FR-030, FR-031)
 *
 * `upsert()` uses `(migration_id, source_id_hash)` as the conflict key. A
 * second invocation with the same key updates the existing row instead of
 * creating a duplicate. The caller (WP05) compares the incoming
 * `source_record_hash` against {@see lookupDestination()} to decide between
 * skip (FR-031: unchanged) and update (FR-031: changed).
 *
 * ### Reverse-walk ordering (R2 mitigation)
 *
 * {@see walkReverseCreation()} yields rows ordered by
 * `last_imported_at DESC, last_run_id DESC`. The secondary key
 * (`last_run_id`, a UUIDv7-shaped value) breaks ties when two rows share a
 * sub-second timestamp — without it, rollback ordering would be
 * non-deterministic.
 *
 * This is **reverse last-imported order**, NOT creation order: `upsert()`
 * refreshes `last_imported_at` (and `last_run_id`) on every re-import, and
 * the stable-surface schema carries no immutable creation/sequence column.
 * A row created first but re-imported later therefore walks first. This is
 * the honest, deterministic contract FR-043 mandates for best-effort
 * rollback (FR-044) — rollback deletes by `destination_uuid`, so *what* is
 * removed is order-independent; the ordering is only an FK/dependency
 * heuristic. The method names retain the historical `…ReverseCreation…`
 * spelling for stable-surface compatibility; the ordering they document is
 * last-imported, not creation.
 *
 * @api
 *
 * @spec FR-025 — backed by the stable `migration_id_map` table
 * @spec FR-028 — `lookupDestination()` on stable surface
 * @spec FR-029 — caller-driven transactional atomicity
 * @spec FR-030 — idempotent upsert
 * @spec FR-031 — change-detection support via stored `source_record_hash`
 */
final class MigrationIdMap
{
    private const string TABLE = 'migration_id_map';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Look up the prior destination for a (migration, source) pair.
     *
     * Returns `null` if no row exists. Never raises on a miss — callers
     * (e.g. WP05) treat `null` as "no prior write, create from scratch".
     *
     * The returned {@see WriteResult} carries the stored `source_record_hash`
     * so the caller can compare against the incoming hash and short-circuit
     * unchanged records (FR-031).
     *
     * @spec FR-028 — stable-surface lookup
     */
    public function lookupDestination(string $migrationId, SourceId $sourceId): ?WriteResult
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::lookupDestination(): $migrationId must be a non-empty string.');
        }

        $sourceIdHash = $sourceId->hash();

        $rows = $this->database->select(self::TABLE, 't')
            ->fields('t', [
                'destination_entity_type',
                'destination_uuid',
                'source_record_hash',
                'last_run_id',
                'last_imported_at',
            ])
            ->condition('migration_id', $migrationId)
            ->condition('source_id_hash', $sourceIdHash)
            ->range(0, 1)
            ->execute();

        foreach ($rows as $row) {
            \assert(\is_array($row));

            return new WriteResult(
                destinationEntityType: (string) $row['destination_entity_type'],
                destinationUuid: (string) $row['destination_uuid'],
                sourceRecordHash: (string) $row['source_record_hash'],
                runId: (string) $row['last_run_id'],
                writtenAt: (string) $row['last_imported_at'],
            );
        }

        return null;
    }

    /**
     * Look up one source identity across ordered migration partitions.
     *
     * Fixed-bundle imports write one id-map partition per bundle. Consumers
     * that do not run inside a process chain (for example media URL resolvers)
     * can use this method to address the split as one logical source. The
     * first matching partition wins; a miss across all partitions is null.
     *
     * @param list<string> $migrationIds Ordered migration ids to consult.
     *
     * @api
     */
    public function lookupDestinationAcross(array $migrationIds, SourceId $sourceId): ?WriteResult
    {
        foreach ($migrationIds as $migrationId) {
            if ($migrationId === '') {
                throw new \InvalidArgumentException('MigrationIdMap::lookupDestinationAcross(): every migration id must be a non-empty string.');
            }

            $result = $this->lookupDestination($migrationId, $sourceId);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Insert-or-update the id-map row keyed by `(migration_id, source_id_hash)`.
     *
     * Returns the {@see WriteResult} reflecting the new row state. Whether
     * the underlying operation was INSERT or UPDATE is intentionally hidden
     * — the caller cares about the post-state, not the path.
     *
     * **Not transactional in itself.** Callers wrap this with the matching
     * entity save in a {@see transactional()} block to keep entity + id-map
     * atomic per FR-029.
     *
     * @param string $migrationId Owning migration id (non-empty).
     * @param SourceId $sourceId Canonical source identity for the row.
     * @param string $destinationEntityType Destination entity type id (non-empty).
     * @param string $destinationUuid UUIDv7 of the destination entity (non-empty).
     * @param string $sourceRecordHash Canonical hash of the source record's payload (non-empty) — used by FR-031 change detection.
     * @param string $runId UUIDv7 of the producing migration run (non-empty).
     * @param \DateTimeImmutable|null $now Timestamp injection seam for tests; defaults to "now in UTC".
     *
     * @throws \InvalidArgumentException When any required string is empty.
     *
     * @spec FR-030 — idempotent upsert
     */
    public function upsert(
        string $migrationId,
        SourceId $sourceId,
        string $destinationEntityType,
        string $destinationUuid,
        string $sourceRecordHash,
        string $runId,
        ?\DateTimeImmutable $now = null,
    ): WriteResult {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::upsert(): $migrationId must be a non-empty string.');
        }
        if ($destinationEntityType === '') {
            throw new \InvalidArgumentException('MigrationIdMap::upsert(): $destinationEntityType must be a non-empty string.');
        }
        if ($destinationUuid === '') {
            throw new \InvalidArgumentException('MigrationIdMap::upsert(): $destinationUuid must be a non-empty string.');
        }
        if ($sourceRecordHash === '') {
            throw new \InvalidArgumentException('MigrationIdMap::upsert(): $sourceRecordHash must be a non-empty string.');
        }
        if ($runId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::upsert(): $runId must be a non-empty string.');
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastImportedAt = $now->format('Y-m-d\TH:i:s\Z');
        $sourceIdHash = $sourceId->hash();

        $exists = $this->lookupDestination($migrationId, $sourceId) !== null;

        if ($exists) {
            $this->database->update(self::TABLE)
                ->fields([
                    'destination_entity_type' => $destinationEntityType,
                    'destination_uuid' => $destinationUuid,
                    'last_imported_at' => $lastImportedAt,
                    'last_run_id' => $runId,
                    'source_record_hash' => $sourceRecordHash,
                ])
                ->condition('migration_id', $migrationId)
                ->condition('source_id_hash', $sourceIdHash)
                ->execute();
        } else {
            $this->database->insert(self::TABLE)
                ->fields([
                    'migration_id',
                    'source_id_hash',
                    'destination_entity_type',
                    'destination_uuid',
                    'last_imported_at',
                    'last_run_id',
                    'source_record_hash',
                ])
                ->values([
                    'migration_id' => $migrationId,
                    'source_id_hash' => $sourceIdHash,
                    'destination_entity_type' => $destinationEntityType,
                    'destination_uuid' => $destinationUuid,
                    'last_imported_at' => $lastImportedAt,
                    'last_run_id' => $runId,
                    'source_record_hash' => $sourceRecordHash,
                ])
                ->execute();
        }

        return new WriteResult(
            destinationEntityType: $destinationEntityType,
            destinationUuid: $destinationUuid,
            sourceRecordHash: $sourceRecordHash,
            runId: $runId,
            writtenAt: $lastImportedAt,
        );
    }

    /**
     * Delete one id-map row by `(migration_id, source_id_hash)`.
     *
     * Returns `true` iff a row was removed. Idempotent: deleting an
     * unknown row returns `false` and is not an error.
     *
     * @spec FR-029 — scoped delete; caller wraps in a transaction with the
     *   destination entity delete to keep the two writes atomic
     */
    public function delete(string $migrationId, SourceId $sourceId): bool
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::delete(): $migrationId must be a non-empty string.');
        }

        // Pre-check existence so we can return a meaningful boolean — the
        // `DatabaseInterface::delete()` builder does not surface the
        // driver's affected-row count.
        if ($this->lookupDestination($migrationId, $sourceId) === null) {
            return false;
        }

        $this->database->delete(self::TABLE)
            ->condition('migration_id', $migrationId)
            ->condition('source_id_hash', $sourceId->hash())
            ->execute();

        return true;
    }

    /**
     * Delete one id-map row by `(migration_id, source_id_hash)` using the
     * raw hash string — useful when the caller is iterating
     * {@see walkReverseCreationWithKeys()} and never reconstitutes a
     * {@see SourceId} (the original keys are not retained on the row).
     *
     * Symmetric to {@see delete()}; the only difference is the input
     * shape (raw hash vs full SourceId). Returns `true` when a row was
     * removed; `false` when no matching row existed (idempotent).
     *
     * @spec FR-043 — rollback walk uses the id-map's deletion primitive
     *   keyed by `source_id_hash`
     */
    public function deleteByHash(string $migrationId, string $sourceIdHash): bool
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationIdMap::deleteByHash(): $migrationId must be a non-empty string.',
            );
        }
        if ($sourceIdHash === '') {
            throw new \InvalidArgumentException(
                'MigrationIdMap::deleteByHash(): $sourceIdHash must be a non-empty string.',
            );
        }

        // Pre-check existence so the boolean return value is meaningful —
        // the query builder does not expose affected-row counts.
        $rows = $this->database->select(self::TABLE, 't')
            ->fields('t', ['source_id_hash'])
            ->condition('migration_id', $migrationId)
            ->condition('source_id_hash', $sourceIdHash)
            ->range(0, 1)
            ->execute();

        $found = false;
        foreach ($rows as $row) {
            \assert(\is_array($row));
            $found = true;
            break;
        }

        if (!$found) {
            return false;
        }

        $this->database->delete(self::TABLE)
            ->condition('migration_id', $migrationId)
            ->condition('source_id_hash', $sourceIdHash)
            ->execute();

        return true;
    }

    /**
     * Delete every id-map row for one migration. Used by the rollback /
     * teardown path (FR-036 — landed in WP08).
     *
     * Returns the number of rows that existed before the delete (counted
     * via {@see countForMigration()} so a meaningful integer comes back
     * even though the query builder does not surface affected-row counts).
     */
    public function deleteAllForMigration(string $migrationId): int
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::deleteAllForMigration(): $migrationId must be a non-empty string.');
        }

        $count = $this->countForMigration($migrationId);
        if ($count === 0) {
            return 0;
        }

        $this->database->delete(self::TABLE)
            ->condition('migration_id', $migrationId)
            ->execute();

        $this->logger->info(
            'MigrationIdMap: deleted all id-map rows for migration.',
            ['migration_id' => $migrationId, 'rows' => $count],
        );

        return $count;
    }

    /**
     * Yield {@see WriteResult}s for one migration in reverse last-imported
     * order (`last_imported_at DESC, last_run_id DESC`).
     *
     * Used by the rollback path (WP08) to delete destination entities in
     * the inverse of the order they were most recently written. The
     * secondary `last_run_id` sort breaks ties when two rows share a
     * sub-second timestamp (R2 mitigation, data-model §8).
     *
     * NOTE: last-imported, not creation order. `upsert()` refreshes
     * `last_imported_at` on every re-import, and the stable-surface table
     * has no immutable creation column — so a re-imported row resorts to
     * the front. See FR-043 / the class docblock. The method name keeps
     * the historical `ReverseCreation` spelling for @api compatibility.
     *
     * **Lazy.** Implemented as a generator so a 100k-row id-map can be
     * walked without loading every row into memory.
     *
     * @return \Generator<int, WriteResult>
     */
    public function walkReverseCreation(string $migrationId): \Generator
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::walkReverseCreation(): $migrationId must be a non-empty string.');
        }

        $rows = $this->database->select(self::TABLE, 't')
            ->fields('t', [
                'destination_entity_type',
                'destination_uuid',
                'source_record_hash',
                'last_run_id',
                'last_imported_at',
            ])
            ->condition('migration_id', $migrationId)
            ->orderBy('last_imported_at', 'DESC')
            ->orderBy('last_run_id', 'DESC')
            ->execute();

        foreach ($rows as $row) {
            \assert(\is_array($row));
            yield new WriteResult(
                destinationEntityType: (string) $row['destination_entity_type'],
                destinationUuid: (string) $row['destination_uuid'],
                sourceRecordHash: (string) $row['source_record_hash'],
                runId: (string) $row['last_run_id'],
                writtenAt: (string) $row['last_imported_at'],
            );
        }
    }

    /**
     * Yield `[source_id_hash, WriteResult]` tuples for one migration in
     * reverse last-imported order (`last_imported_at DESC, last_run_id
     * DESC`) — see {@see walkReverseCreation()} for why this is
     * last-imported and not creation order.
     *
     * Sibling of {@see walkReverseCreation()} for the rollback walker
     * (WP08): the walker needs both the {@see WriteResult} (to hand to
     * `DestinationPluginInterface::rollback()`) and the
     * `source_id_hash` (to delete the corresponding id-map row after a
     * successful rollback). The base method intentionally hides
     * `source_id_hash` because callers reaching for the prior write
     * already know the {@see SourceId}; the rollback walk doesn't.
     *
     * **Lazy.** Same generator shape as {@see walkReverseCreation()}.
     *
     * @return \Generator<int, array{0: string, 1: WriteResult}>
     *
     * @spec FR-043 — reverse last-imported walk order with deletion key
     */
    public function walkReverseCreationWithKeys(string $migrationId): \Generator
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationIdMap::walkReverseCreationWithKeys(): $migrationId must be a non-empty string.',
            );
        }

        $rows = $this->database->select(self::TABLE, 't')
            ->fields('t', [
                'source_id_hash',
                'destination_entity_type',
                'destination_uuid',
                'source_record_hash',
                'last_run_id',
                'last_imported_at',
            ])
            ->condition('migration_id', $migrationId)
            ->orderBy('last_imported_at', 'DESC')
            ->orderBy('last_run_id', 'DESC')
            ->execute();

        foreach ($rows as $row) {
            \assert(\is_array($row));
            yield [
                (string) $row['source_id_hash'],
                new WriteResult(
                    destinationEntityType: (string) $row['destination_entity_type'],
                    destinationUuid: (string) $row['destination_uuid'],
                    sourceRecordHash: (string) $row['source_record_hash'],
                    runId: (string) $row['last_run_id'],
                    writtenAt: (string) $row['last_imported_at'],
                ),
            ];
        }
    }

    /**
     * Most recent `last_imported_at` value across rows for one migration, or
     * `null` when the migration has no id-map rows yet.
     *
     * Used by `bin/waaseyaa import:status` (WP06) to surface the LAST RUN
     * column without loading rows. The full per-record audit trail
     * (`migration_run_state`) is WP07's surface; for WP06 the id-map row
     * timestamp is the cheapest, freshness-correct signal available.
     *
     * @spec FR-034 — `import:status` LAST RUN surface
     */
    public function maxLastImportedAt(string $migrationId): ?string
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationIdMap::maxLastImportedAt(): $migrationId must be a non-empty string.',
            );
        }

        $rows = $this->database->select(self::TABLE, 't')
            ->fields('t', ['last_imported_at'])
            ->condition('migration_id', $migrationId)
            ->orderBy('last_imported_at', 'DESC')
            ->range(0, 1)
            ->execute();

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $value = $row['last_imported_at'] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Count of id-map rows for one migration. Used by `import:status`
     * (WP06) for a cheap progress signal without loading rows.
     */
    public function countForMigration(string $migrationId): int
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('MigrationIdMap::countForMigration(): $migrationId must be a non-empty string.');
        }

        $rows = $this->database->select(self::TABLE, 't')
            ->condition('migration_id', $migrationId)
            ->countQuery()
            ->execute();

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $count = $row['count'] ?? 0;

            return \is_numeric($count) ? (int) $count : 0;
        }

        return 0;
    }

    /**
     * Run $body inside a database transaction. Convenience seam over
     * {@see DatabaseInterface::transaction()} that callers (WP05's
     * `EntityDestination::write()`) use to keep entity + id-map mutations
     * atomic per FR-029.
     *
     * On any throw from $body the transaction is rolled back and the
     * exception is re-thrown.
     *
     * @template T
     * @param callable():T $body
     * @return T
     *
     * @spec FR-029 — caller-driven transactional atomicity
     */
    public function transactional(callable $body): mixed
    {
        $tx = $this->database->transaction();

        try {
            $result = $body();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        $tx->commit();

        return $result;
    }
}
