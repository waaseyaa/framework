<?php

declare(strict_types=1);

namespace Waaseyaa\Migration;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Repository for the `migration_run_state` table (FR-038, FR-037).
 *
 * Tracks the LATEST per-record outcome for every `(migration_id, source_id_hash)`
 * pair so:
 *  - The runner can record success / skip / error as it walks the source.
 *  - `import:resume` can find the last completed checkpoint for a migration
 *    and continue from there with the prior `run_id` (FR-037).
 *  - `import:status` can render real `FAILED` / `SKIPPED` counts (FR-038).
 *
 * The table is a non-entity supporting table — direct {@see DatabaseInterface}
 * access is the canonical path per `.claude/rules/entity-storage-invariant.md`.
 * Going through `EntityRepository` would buy nothing here (no event dispatch
 * needs, no hydration semantics).
 *
 * ### Stable-surface boundary
 *
 * Unlike {@see MigrationIdMap}, this class is **mission-internal**. Only
 * three read-side methods are tagged `@api` for use by `import:status` /
 * `import:resume`:
 *  - {@see latestPositionForRun()}
 *  - {@see latestRunForMigration()}
 *  - {@see lookupItem()}
 *  - {@see countByStatus()}
 *
 * The write-side methods ({@see recordSuccess()}, {@see recordError()},
 * {@see recordSkipped()}) are marked `@internal` — only the runner calls
 * them. Schema changes do NOT require a charter amendment.
 *
 * ### Idempotency
 *
 * The PRIMARY KEY is `(migration_id, source_id_hash)`; the `recordX()`
 * helpers all do a portable lookup-then-update-or-insert (same strategy as
 * {@see MigrationIdMap::upsert()}), so re-running a migration overwrites the
 * prior per-record outcome. The table tracks the LATEST outcome per record,
 * not a history.
 *
 * ### Clock-tie determinism (R-something from WP04)
 *
 * Rows tied on `updated_at` resolve deterministically by `run_id` — UUIDv7
 * is monotonically increasing within a single producer, so the latest run
 * for a migration sorts last under `ORDER BY updated_at DESC, run_id DESC`.
 *
 * @internal — write methods are internal; only the runner mutates rows.
 *
 * @spec FR-037 — resume from last completed checkpoint
 * @spec FR-038 — per-record progress tracking
 */
final class MigrationRunState
{
    /** Stable string codes for `item_status`. */
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';
    public const string STATUS_SKIPPED = 'skipped';

    private const string TABLE = 'migration_run_state';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record a per-record success outcome. Upsert semantics — overwrites the
     * prior row for `(migrationId, sourceIdHash)`.
     *
     * @param string $migrationId Non-empty migration id.
     * @param string $sourceIdHash {@see SourceId::hash()} of the record.
     * @param string $runId UUIDv7 of the producing run; must be non-empty.
     * @param int $position Monotonically increasing per-run counter (1-based).
     * @param \DateTimeImmutable|null $now Timestamp injection seam for tests; defaults to "now in UTC".
     *
     * @internal Called by {@see Runner\MigrationRunner}.
     *
     * @spec FR-038 — per-record progress tracking
     */
    public function recordSuccess(
        string $migrationId,
        string $sourceIdHash,
        string $runId,
        int $position,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->upsert(
            migrationId: $migrationId,
            sourceIdHash: $sourceIdHash,
            runId: $runId,
            itemStatus: self::STATUS_SUCCESS,
            errorCode: null,
            errorMessage: null,
            position: $position,
            now: $now,
        );
    }

    /**
     * Record a per-record error outcome with the typed exception's stable
     * code and message.
     *
     * @internal Called by {@see Runner\MigrationRunner}.
     *
     * @spec FR-038 — per-record progress tracking
     * @spec FR-046 — per-record error capture
     */
    public function recordError(
        string $migrationId,
        string $sourceIdHash,
        string $runId,
        int $position,
        string $errorCode,
        string $errorMessage,
        ?\DateTimeImmutable $now = null,
    ): void {
        if ($errorCode === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::recordError(): $errorCode must be a non-empty string.',
            );
        }

        $this->upsert(
            migrationId: $migrationId,
            sourceIdHash: $sourceIdHash,
            runId: $runId,
            itemStatus: self::STATUS_ERROR,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            position: $position,
            now: $now,
        );
    }

    /**
     * Record a per-record skip outcome (idempotent hash-match, FR-031, or
     * `--dry-run` FR-039).
     *
     * @internal Called by {@see Runner\MigrationRunner}.
     *
     * @spec FR-038 — per-record progress tracking
     */
    public function recordSkipped(
        string $migrationId,
        string $sourceIdHash,
        string $runId,
        int $position,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->upsert(
            migrationId: $migrationId,
            sourceIdHash: $sourceIdHash,
            runId: $runId,
            itemStatus: self::STATUS_SKIPPED,
            errorCode: null,
            errorMessage: null,
            position: $position,
            now: $now,
        );
    }

    /**
     * Return the maximum `position` recorded for a `(migrationId, runId)`
     * pair, or `null` if no row matches.
     *
     * Used by the resume loop to compute "where did the prior run get to?"
     * without scanning the table.
     *
     * @api
     *
     * @spec FR-037 — resume from last completed checkpoint
     */
    public function latestPositionForRun(string $migrationId, string $runId): ?int
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::latestPositionForRun(): $migrationId must be a non-empty string.',
            );
        }
        if ($runId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::latestPositionForRun(): $runId must be a non-empty string.',
            );
        }

        $rows = $this->database->query(
            \sprintf(
                'SELECT MAX(position) AS max_position FROM %s WHERE migration_id = ? AND run_id = ?',
                self::TABLE,
            ),
            [$migrationId, $runId],
        );

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $value = $row['max_position'] ?? null;
            return \is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * Return the most recent `run_id` recorded against a migration, or
     * `null` if no row exists.
     *
     * "Most recent" is `ORDER BY updated_at DESC, run_id DESC LIMIT 1` —
     * UUIDv7's monotonic prefix breaks ties when two rows share a
     * sub-second timestamp (R-clock-collision from WP04).
     *
     * @api
     *
     * @spec FR-037 — resume reuses prior run id
     */
    public function latestRunForMigration(string $migrationId): ?string
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::latestRunForMigration(): $migrationId must be a non-empty string.',
            );
        }

        $rows = $this->database->query(
            \sprintf(
                'SELECT run_id FROM %s WHERE migration_id = ? ORDER BY updated_at DESC, run_id DESC LIMIT 1',
                self::TABLE,
            ),
            [$migrationId],
        );

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $runId = $row['run_id'] ?? null;
            return \is_string($runId) && $runId !== '' ? $runId : null;
        }

        return null;
    }

    /**
     * Look up the per-record outcome for a single `(migrationId, sourceIdHash)`.
     *
     * Returns `null` if no row exists. Used by tests + integration tooling;
     * the runner does not need this on the hot path.
     *
     * @return null|array{
     *     migration_id: string,
     *     source_id_hash: string,
     *     run_id: string,
     *     item_status: string,
     *     error_code: ?string,
     *     error_message: ?string,
     *     position: int,
     *     updated_at: string,
     * }
     *
     * @api
     *
     * @spec FR-038 — per-record progress lookup
     */
    public function lookupItem(string $migrationId, string $sourceIdHash): ?array
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::lookupItem(): $migrationId must be a non-empty string.',
            );
        }
        if ($sourceIdHash === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::lookupItem(): $sourceIdHash must be a non-empty string.',
            );
        }

        $rows = $this->database->query(
            \sprintf(
                'SELECT migration_id, source_id_hash, run_id, item_status, '
                . 'error_code, error_message, position, updated_at '
                . 'FROM %s WHERE migration_id = ? AND source_id_hash = ? LIMIT 1',
                self::TABLE,
            ),
            [$migrationId, $sourceIdHash],
        );

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $errorCode = $row['error_code'] ?? null;
            $errorMessage = $row['error_message'] ?? null;
            return [
                'migration_id' => (string) $row['migration_id'],
                'source_id_hash' => (string) $row['source_id_hash'],
                'run_id' => (string) $row['run_id'],
                'item_status' => (string) $row['item_status'],
                'error_code' => \is_string($errorCode) ? $errorCode : null,
                'error_message' => \is_string($errorMessage) ? $errorMessage : null,
                'position' => (int) $row['position'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return null;
    }

    /**
     * Return the count of rows in each `item_status` bucket for one migration.
     *
     * Used by `import:status` (WP06) to render real `FAILED` / `SKIPPED`
     * columns now that progress tracking lands.
     *
     * @return array{success: int, error: int, skipped: int}
     *
     * @api
     *
     * @spec FR-038 — progress tracking surfaced by import:status
     */
    public function countByStatus(string $migrationId): array
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::countByStatus(): $migrationId must be a non-empty string.',
            );
        }

        $counts = [
            self::STATUS_SUCCESS => 0,
            self::STATUS_ERROR => 0,
            self::STATUS_SKIPPED => 0,
        ];

        $rows = $this->database->query(
            \sprintf(
                'SELECT item_status, COUNT(*) AS c FROM %s WHERE migration_id = ? GROUP BY item_status',
                self::TABLE,
            ),
            [$migrationId],
        );

        foreach ($rows as $row) {
            \assert(\is_array($row));
            $itemStatus = (string) ($row['item_status'] ?? '');
            $count = $row['c'] ?? 0;
            if (\array_key_exists($itemStatus, $counts) && \is_numeric($count)) {
                $counts[$itemStatus] = (int) $count;
            }
        }

        return [
            'success' => $counts[self::STATUS_SUCCESS],
            'error' => $counts[self::STATUS_ERROR],
            'skipped' => $counts[self::STATUS_SKIPPED],
        ];
    }

    /**
     * Delete every row for one migration.
     *
     * Used by tests to reset state between scenarios and by potential
     * future operator tooling (`migration:reset` lands in WP08).
     *
     * @internal
     */
    public function clearForMigration(string $migrationId): int
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::clearForMigration(): $migrationId must be a non-empty string.',
            );
        }

        return $this->database->delete(self::TABLE)
            ->condition('migration_id', $migrationId)
            ->execute();
    }

    /**
     * Public alias for {@see clearForMigration()} consumed by
     * {@see \Waaseyaa\CLI\Command\Import\ImportResetCommand} (WP08).
     *
     * The internal `clearForMigration()` is `@internal`; the reset CLI
     * surface needs a stable `@api` method to call. Behaviour is
     * identical — same SQL, same return shape.
     *
     * @api
     *
     * @spec FR-036 — `import:reset` clears progress alongside the id-map
     */
    public function deleteAllForMigration(string $migrationId): int
    {
        return $this->clearForMigration($migrationId);
    }

    /**
     * Upsert one row via a portable lookup-then-update-or-insert, matching
     * {@see MigrationIdMap::upsert()}.
     *
     * The previous implementation emitted `INSERT ... ON CONFLICT (a, b) DO
     * UPDATE`, which is SQLite/Postgres-only syntax. That contradicted the
     * schema layer's portability contract ({@see Schema\MigrationRunStateSchema}
     * advertises DDL that "works on SQLite, MySQL, Postgres without driver
     * branching"): the table is MySQL-portable but the write was not. The
     * query-builder path below works on every driver the schema targets with
     * no dialect branching. Callers wrap the per-record heartbeat in their own
     * transaction, so the two-statement existence check is consistent.
     */
    private function upsert(
        string $migrationId,
        string $sourceIdHash,
        string $runId,
        string $itemStatus,
        ?string $errorCode,
        ?string $errorMessage,
        int $position,
        ?\DateTimeImmutable $now,
    ): void {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::upsert(): $migrationId must be a non-empty string.',
            );
        }
        if ($sourceIdHash === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::upsert(): $sourceIdHash must be a non-empty string.',
            );
        }
        if ($runId === '') {
            throw new \InvalidArgumentException(
                'MigrationRunState::upsert(): $runId must be a non-empty string.',
            );
        }
        if ($position < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationRunState::upsert(): $position must be >= 0, got %d.',
                $position,
            ));
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updatedAt = $now->format('Y-m-d\TH:i:s\Z');

        $this->logger->debug('MigrationRunState: upsert row', [
            'migration_id' => $migrationId,
            'source_id_hash' => $sourceIdHash,
            'run_id' => $runId,
            'item_status' => $itemStatus,
            'position' => $position,
        ]);

        $exists = $this->lookupItem($migrationId, $sourceIdHash) !== null;

        if ($exists) {
            $this->database->update(self::TABLE)
                ->fields([
                    'run_id' => $runId,
                    'item_status' => $itemStatus,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'position' => $position,
                    'updated_at' => $updatedAt,
                ])
                ->condition('migration_id', $migrationId)
                ->condition('source_id_hash', $sourceIdHash)
                ->execute();

            return;
        }

        $this->database->insert(self::TABLE)
            ->fields([
                'migration_id',
                'source_id_hash',
                'run_id',
                'item_status',
                'error_code',
                'error_message',
                'position',
                'updated_at',
            ])
            ->values([
                'migration_id' => $migrationId,
                'source_id_hash' => $sourceIdHash,
                'run_id' => $runId,
                'item_status' => $itemStatus,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'position' => $position,
                'updated_at' => $updatedAt,
            ])
            ->execute();
    }
}
