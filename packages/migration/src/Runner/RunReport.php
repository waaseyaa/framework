<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Runner;

use Waaseyaa\Migration\Advisory\SaveAdvisoryEvidence;

/**
 * Output value object summarising a single {@see MigrationRunner::run()} call.
 *
 * Returned for happy-path runs; also carried by
 * {@see \Waaseyaa\Migration\Exception\MigrationAbortedException::$report} so
 * aborted runs surface a partial summary that the CLI can render.
 *
 * Fields:
 *  - `$total`         — count reported by the source plugin (-1 when unknown).
 *  - `$imported`      — records that produced a fresh write (or a changed
 *    update).
 *  - `$skipped`       — records the destination treated as no-ops because
 *    their `source_record_hash` already existed (FR-031), PLUS any records
 *    skipped via dry-run (FR-039).
 *  - `$failed`        — records that raised a typed exception (FR-046).
 *  - `$errors`        — list of {@see RecordError} entries (capped at
 *    {@see self::ERROR_CAP}).
 *  - `$warnings`      — successful save-advisory acknowledgements (capped at
 *    {@see self::WARNING_CAP}); contains hashes and tokens, never source values.
 *  - `$aborted`       — `true` when the runner short-circuited via
 *    {@see \Waaseyaa\Migration\Exception\MigrationAbortedException}.
 *
 * @api
 *
 * @spec FR-032 — `import:run` summary surface
 * @spec FR-046 — per-record error capture
 * @spec FR-048 — run-level abort surfaces partial report
 */
final readonly class RunReport
{
    /** @var list<SaveAdvisoryEvidence> */
    public array $warnings;

    /**
     * Maximum number of per-record errors retained in memory. The full audit
     * trail lives in `migration_run_state` (WP07); this cap keeps the report
     * value object bounded on million-error runs.
     */
    public const int ERROR_CAP = 100;
    public const int WARNING_CAP = 100;

    /**
     * @param string $migrationId Id of the migration this report describes. Non-empty.
     * @param string $runId UUIDv7 of the run this report describes. Non-empty.
     * @param int $total Total source-record count reported by the source plugin; `-1` when the plugin could not precompute (`count()` returned `null`).
     * @param int $imported Records that produced a fresh write or a changed update. `>= 0`.
     * @param int $skipped Records skipped (idempotent hash-match per FR-031, OR `--dry-run` per FR-039). `>= 0`.
     * @param int $failed Records that raised a typed per-record exception. `>= 0`.
     * @param list<RecordError> $errors Captured per-record errors (FR-046), capped at {@see self::ERROR_CAP}.
     * @param \DateTimeImmutable $startedAt Wall-clock instant the run began.
     * @param \DateTimeImmutable $finishedAt Wall-clock instant the run ended (success, abort, or short-circuit).
     * @param bool $aborted `true` when the runner raised {@see \Waaseyaa\Migration\Exception\MigrationAbortedException}; `false` on normal completion.
     * @param array<int, mixed> $warnings Bounded advisory acknowledgements produced by successful writes.
     *
     * @throws \InvalidArgumentException When counts are negative, ids are empty, or an error/warning list is invalid or exceeds its cap.
     */
    public function __construct(
        public string $migrationId,
        public string $runId,
        public int $total,
        public int $imported,
        public int $skipped,
        public int $failed,
        public array $errors,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        public bool $aborted,
        array $warnings = [],
    ) {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('RunReport::$migrationId must be a non-empty string.');
        }
        if ($runId === '') {
            throw new \InvalidArgumentException('RunReport::$runId must be a non-empty string.');
        }
        if ($total < -1) {
            throw new \InvalidArgumentException(\sprintf(
                'RunReport::$total must be >= -1, got %d.',
                $total,
            ));
        }
        foreach (['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(\sprintf(
                    'RunReport::$%s must be >= 0, got %d.',
                    $name,
                    $value,
                ));
            }
        }
        if (\count($errors) > self::ERROR_CAP) {
            throw new \InvalidArgumentException(\sprintf(
                'RunReport::$errors must not exceed ERROR_CAP=%d, got %d entries.',
                self::ERROR_CAP,
                \count($errors),
            ));
        }
        if (!array_is_list($warnings)) {
            throw new \InvalidArgumentException('RunReport::$warnings must be a list.');
        }
        if (count($warnings) > self::WARNING_CAP) {
            throw new \InvalidArgumentException(\sprintf(
                'RunReport::$warnings must not exceed WARNING_CAP=%d, got %d entries.',
                self::WARNING_CAP,
                count($warnings),
            ));
        }
        foreach ($warnings as $warning) {
            if (!$warning instanceof SaveAdvisoryEvidence) {
                throw new \InvalidArgumentException('RunReport::$warnings contains an invalid entry.');
            }
        }
        /** @var list<SaveAdvisoryEvidence> $warnings */
        $this->warnings = $warnings;
        // PHPDoc declares `list<RecordError>` so static analysis verifies entry
        // types at the call site; no runtime check needed here.
        if ($startedAt > $finishedAt) {
            throw new \InvalidArgumentException(
                'RunReport::$startedAt must be <= $finishedAt.',
            );
        }
    }

    /**
     * Total records the runner processed (imported + skipped + failed).
     *
     * Distinct from `$total` because `$total` is what the source plugin
     * reported up-front; the processed count is what the loop actually
     * consumed.
     */
    public function processed(): int
    {
        return $this->imported + $this->skipped + $this->failed;
    }

    /**
     * Compute the run state for {@see \Waaseyaa\Migration\Cli\ImportStatusCommand}.
     *
     * @return 'complete'|'partial'|'failed'|'pending'|'aborted'
     */
    public function state(): string
    {
        if ($this->aborted) {
            return 'aborted';
        }
        if ($this->failed > 0) {
            return 'failed';
        }
        if ($this->total === -1) {
            return $this->processed() === 0 ? 'pending' : 'complete';
        }
        if ($this->processed() >= $this->total) {
            return 'complete';
        }

        return 'partial';
    }

    /**
     * One-line CLI-friendly summary suitable for stdout.
     *
     * Format: `"<migration_id>: <state> (<processed>/<total>, <failed> failed, <skipped> skipped)"`.
     * Total renders as `"?"` when unknown (-1).
     */
    public function summaryLine(): string
    {
        return \sprintf(
            '%s: %s (%d/%s, %d failed, %d skipped)',
            $this->migrationId,
            $this->state(),
            $this->processed(),
            $this->total === -1 ? '?' : (string) $this->total,
            $this->failed,
            $this->skipped,
        );
    }
}
