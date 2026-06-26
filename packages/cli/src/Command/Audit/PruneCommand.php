<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Audit;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * `bin/waaseyaa audit:prune --older-than=<duration> [--kind=<glob>] [--dry-run] [--confirm]`
 *
 * Deletes audit_event rows older than the given ISO-8601 duration
 * (e.g. `PT1H`, `P30D`, `P1Y`). Emits one self-audit event of kind
 * `audit.retention_pruned` after each real execution (FR-012).
 *
 * Destructive operation: real deletion requires `--confirm`. Without it (and
 * without `--dry-run`) the command echoes the resolved cutoff timestamp and the
 * exact row count it would delete, then refuses and exits 0 — nothing is
 * deleted and no self-audit event is recorded. This guards against typo-prone
 * `--older-than` durations silently destroying the append-only audit log.
 *
 * Chain-safety (WP4): sealed rows (covered by a non-genesis checkpoint) are
 * only pruned at WHOLE checkpoint-segment boundaries. A segment is eligible
 * when every event row it covers was created before the cutoff. Eligible
 * segments' checkpoints are marked `pruned=1` so `audit:verify` can
 * distinguish a sanctioned retention prune from a malicious row deletion.
 *
 * Unsealed-tail rows (id > last checkpoint segment_end_id) are pruned with the
 * legacy created_at + optional kind filter — they have no chain yet.
 *
 * Options:
 *   --older-than  ISO-8601 duration string (required). Events created before
 *                 `now() - interval` are eligible for pruning.
 *   --kind        Glob pattern matched against AuditEventKind values:
 *                   `*`         → all kinds (default)
 *                   `entity.*`  → all entity.* cases
 *                   literal     → single exact kind
 *                 NOTE: --kind only applies to the unsealed tail. Sealed
 *                 segments are always pruned as a whole (chain integrity).
 *   --dry-run     Print the count that would be pruned; do not delete.
 *   --confirm     Required for real deletion. Without it, the command refuses
 *                 to delete and prints the cutoff + row count it would remove.
 *
 * @api
 */
final class PruneCommand
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AuditQueryInterface $query,
        private readonly AuditWriterInterface $writer,
        private readonly DatabaseInterface $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $olderThanRaw = $io->option('older-than');
        if (!is_string($olderThanRaw) || $olderThanRaw === '') {
            $io->error('audit:prune: --older-than is required (e.g. P30D, PT1H, P1Y).');

            return 1;
        }

        try {
            $interval = new \DateInterval($olderThanRaw);
        } catch (\Exception $e) {
            $io->error(sprintf(
                'audit:prune: --older-than "%s" is not a valid ISO-8601 duration: %s',
                $olderThanRaw,
                $e->getMessage(),
            ));

            return 1;
        }

        $kindRaw = $io->option('kind');
        $kindPattern = (is_string($kindRaw) && $kindRaw !== '') ? $kindRaw : '*';

        $kinds = $this->resolveKinds($kindPattern);
        if ($kinds === []) {
            $io->error(sprintf(
                'audit:prune: --kind "%s" matched no known AuditEventKind values.',
                $kindPattern,
            ));

            return 1;
        }

        $cutoff = new \DateTimeImmutable('now')->sub($interval);
        $cutoffSql = $cutoff->format('Y-m-d H:i:s');

        $isDryRun = (bool) $io->option('dry-run');

        // ----------------------------------------------------------------
        // Compute sealed horizon (WP4): highest segment_end_id across all
        // non-genesis, non-yet-pruned checkpoints whose entire segment is
        // older than the cutoff.
        //
        // A segment is "entirely older" when the maximum created_at among its
        // audit_event rows is < cutoff. A segment with NO surviving rows (i.e.
        // already pruned in a prior pass) also counts — the sub-select returns
        // NULL for an empty range and NULL < cutoff is treated as true (skip
        // the already-pruned=1 ones for deletion but still advance the horizon).
        // ----------------------------------------------------------------
        $horizon = $this->computeSealedHorizon($cutoffSql);

        // ----------------------------------------------------------------
        // Compute unsealed-tail count: rows with id > global checkpoint max.
        // ----------------------------------------------------------------
        $globalMaxEndId = $this->globalMaxCheckpointEndId();

        $unsealedCount = $this->countUnsealedTailRows(
            $globalMaxEndId,
            $cutoffSql,
            $kindPattern,
            $kinds,
        );

        // ----------------------------------------------------------------
        // Legacy total count (for display / backward-compat attributes).
        // ----------------------------------------------------------------
        $auditQuery = new AuditQuery(
            kinds: $kinds,
            to: $cutoff,
        );
        $count = $this->query->count($auditQuery);

        if ($isDryRun) {
            $sealedRowCount = $horizon > 0
                ? $this->countSealedRowsUpTo($horizon)
                : 0;

            $io->writeln(sprintf(
                'Would prune %d sealed event row(s) (up to checkpoint end id %d) and %d unsealed tail row(s) (kind: %s, older than: %s, cutoff: %s). [dry-run]',
                $sealedRowCount,
                $horizon,
                $unsealedCount,
                $kindPattern,
                $olderThanRaw,
                $cutoff->format(\DateTimeInterface::ATOM),
            ));

            return 0;
        }

        // Destructive-op guard (C-31): real deletion requires an explicit
        // --confirm. Without it, echo the resolved cutoff + the exact row count
        // that WOULD be deleted, then refuse — record no self-audit event and
        // delete no rows. This refusal is unconditional (interactive or not),
        // matching the import:reset / import:rollback idiom.
        $isConfirmed = (bool) $io->option('confirm');

        if (!$isConfirmed) {
            $io->writeln(sprintf(
                'Refusing to prune %d audit events without --confirm (kind: %s, older than: %s, cutoff: %s).',
                $count,
                $kindPattern,
                $olderThanRaw,
                $cutoff->format(\DateTimeInterface::ATOM),
            ));
            $io->writeln('Re-run with --confirm to delete, or --dry-run to preview.');

            return 0;
        }

        // ----------------------------------------------------------------
        // Resolve the pruned checkpoint's hash for the self-audit record.
        // ----------------------------------------------------------------
        $prunedCheckpointHash = $horizon > 0
            ? $this->checkpointHashForHorizon($horizon)
            : '';

        // Self-audit before deletion (FR-012, WP4): record the prune event
        // FIRST so it lands as an unsealed row with id > horizon — it is never
        // inside the sealed range being deleted.
        $this->writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::AuditRetentionPruned,
            accountUid: 0,
            subjectUri: 'audit:prune',
            outcome: 'allowed',
            severity: 'info',
            attributes: [
                'kind_pattern'            => $kindPattern,
                'older_than'              => $olderThanRaw,
                'deleted_count'           => $count,
                'cutoff'                  => $cutoff->format(\DateTimeInterface::ATOM),
                'sealed_pruned_through_id' => $horizon,
                'pruned_checkpoint_hash'  => $prunedCheckpointHash,
                'unsealed_deleted_count'  => $unsealedCount,
            ],
        ));

        // ----------------------------------------------------------------
        // Path A: delete whole sealed segments up to horizon.
        // NOT kind-filtered — whole-segment deletion is required for chain
        // integrity. Mark covered non-genesis checkpoints as pruned=1.
        // ----------------------------------------------------------------
        if ($horizon > 0) {
            $this->db->delete('audit_event')
                ->condition('id', $horizon, '<=')
                ->execute();

            // Mark all non-genesis checkpoints whose segment is entirely inside
            // the pruned range.
            $this->markCheckpointsPruned($horizon);
        }

        // ----------------------------------------------------------------
        // Path B: delete unsealed-tail rows (id > globalMaxEndId) with
        // legacy created_at + optional kind filter.
        // ----------------------------------------------------------------
        $deleteTail = $this->db->delete('audit_event');
        $deleteTail->condition('id', $globalMaxEndId, '>');
        $deleteTail->condition('created_at', $cutoffSql, '<');

        if ($kindPattern !== '*') {
            $kindValues = array_map(static fn(AuditEventKind $k): string => $k->value, $kinds);
            $deleteTail->condition('event_kind', $kindValues, 'IN');
        }

        $deleteTail->execute();

        $this->logger->info('audit:prune completed', [
            'sealed_pruned_through_id' => $horizon,
            'unsealed_deleted_count'   => $unsealedCount,
            'kind_pattern'             => $kindPattern,
            'cutoff'                   => $cutoff->format(\DateTimeInterface::ATOM),
        ]);

        $io->writeln(sprintf(
            'Pruned %d audit events (kind: %s, older than: %s).',
            $count,
            $kindPattern,
            $olderThanRaw,
        ));

        return 0;
    }

    /**
     * Resolve glob pattern to a list of matching AuditEventKind cases.
     *
     * - `*`          → all cases
     * - `entity.*`   → all cases whose value starts with `entity.`
     * - `literal`    → single exact match via AuditEventKind::tryFrom()
     *
     * @return AuditEventKind[]
     */
    private function resolveKinds(string $pattern): array
    {
        $all = AuditEventKind::cases();

        if ($pattern === '*') {
            return $all;
        }

        // Prefix glob: "entity.*" → values starting with "entity."
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -2); // strip ".*"

            return array_values(array_filter(
                $all,
                static fn(AuditEventKind $k): bool => str_starts_with($k->value, $prefix . '.'),
            ));
        }

        // Exact match.
        $case = AuditEventKind::tryFrom($pattern);

        return $case !== null ? [$case] : [];
    }

    /**
     * Compute the sealed horizon: the highest segment_end_id across non-genesis
     * checkpoints whose entire segment of audit_event rows is older than cutoff.
     *
     * A segment qualifies when MAX(created_at) over its rows < cutoff, OR when
     * the segment has no surviving rows (already pruned prior pass). Already-
     * pruned=1 checkpoints are included in the horizon scan (they don't block
     * a higher horizon) but are skipped during the DELETE+UPDATE pass.
     */
    private function computeSealedHorizon(string $cutoffSql): int
    {
        // Load all non-genesis checkpoints.
        $checkpoints = $this->loadNonGenesisCheckpoints();

        $horizon = 0;
        foreach ($checkpoints as $cp) {
            $segStart = (int) $cp['segment_start_id'];
            $segEnd   = (int) $cp['segment_end_id'];

            // Check if any row in this segment is NOT older than cutoff.
            $maxCreatedAtRaw = $this->db
                ->select('audit_event')
                ->fields('audit_event', ['created_at'])
                ->condition('id', $segStart, '>=')
                ->condition('id', $segEnd, '<=')
                ->orderBy('created_at', 'DESC')
                ->range(0, 1)
                ->execute();

            $maxCreatedAtRows = iterator_to_array($maxCreatedAtRaw, false);

            if ($maxCreatedAtRows !== []) {
                $maxCreatedAt = (string) $maxCreatedAtRows[0]['created_at'];
                // If any row is NOT older than cutoff, skip this segment.
                if ($maxCreatedAt >= $cutoffSql) {
                    continue;
                }
            }
            // No rows surviving in segment (already pruned) OR max < cutoff → eligible.

            if ($segEnd > $horizon) {
                $horizon = $segEnd;
            }
        }

        return $horizon;
    }

    /**
     * Return the global maximum segment_end_id across ALL checkpoints
     * (genesis and non-genesis). This is the boundary between sealed and
     * unsealed rows.
     */
    private function globalMaxCheckpointEndId(): int
    {
        $rows = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['segment_end_id'])
                ->orderBy('segment_end_id', 'DESC')
                ->range(0, 1)
                ->execute(),
            false,
        );

        return $rows !== [] ? (int) $rows[0]['segment_end_id'] : 0;
    }

    /**
     * Count unsealed tail rows that would be deleted by path B.
     *
     * @param AuditEventKind[] $kinds
     */
    private function countUnsealedTailRows(
        int $globalMaxEndId,
        string $cutoffSql,
        string $kindPattern,
        array $kinds,
    ): int {
        $select = $this->db
            ->select('audit_event')
            ->fields('audit_event', ['id'])
            ->condition('id', $globalMaxEndId, '>')
            ->condition('created_at', $cutoffSql, '<');

        if ($kindPattern !== '*') {
            $kindValues = array_map(static fn(AuditEventKind $k): string => $k->value, $kinds);
            $select->condition('event_kind', $kindValues, 'IN');
        }

        return count(iterator_to_array($select->execute(), false));
    }

    /**
     * Count sealed event rows with id <= horizon.
     */
    private function countSealedRowsUpTo(int $horizon): int
    {
        return count(iterator_to_array(
            $this->db
                ->select('audit_event')
                ->fields('audit_event', ['id'])
                ->condition('id', $horizon, '<=')
                ->execute(),
            false,
        ));
    }

    /**
     * Fetch the checkpoint_hash of the non-genesis checkpoint whose segment_end_id == horizon.
     * Returns '' if no such checkpoint exists.
     */
    private function checkpointHashForHorizon(int $horizon): string
    {
        $rows = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['checkpoint_hash'])
                ->condition('segment_end_id', $horizon)
                ->condition('is_genesis', 0)
                ->execute(),
            false,
        );

        return $rows !== [] ? (string) $rows[0]['checkpoint_hash'] : '';
    }

    /**
     * Mark all non-genesis checkpoints with segment_end_id <= horizon as pruned.
     * Uses raw SQL via getConnection() since DatabaseInterface::update() is
     * blocked on the audit database but we operate on the raw db here.
     */
    private function markCheckpointsPruned(int $horizon): void
    {
        $this->db
            ->update('audit_checkpoint')
            ->fields(['pruned' => 1])
            ->condition('is_genesis', 0)
            ->condition('segment_end_id', $horizon, '<=')
            ->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadNonGenesisCheckpoints(): array
    {
        return iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', [
                    'segment_start_id',
                    'segment_end_id',
                    'pruned',
                ])
                ->condition('is_genesis', 0)
                ->orderBy('segment_end_id', 'ASC')
                ->execute(),
            false,
        );
    }
}
