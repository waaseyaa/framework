<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

/**
 * Immutable result value object returned by {@see AuditChainVerifier::verify()}.
 *
 * Two states:
 *  - **Intact**: `$ok === true`, all sealed checkpoints and their row chains
 *    verified successfully. `$firstBrokenId` and `$failureKind` are `null`.
 *  - **Broken**: `$ok === false`, tamper or corruption detected at `$firstBrokenId`
 *    with a machine-readable `$failureKind` and a human-readable `$message`.
 *
 * `$failureKind` values (stable set — extend additively, never rename):
 *  - `row_content`      — row's stored `row_hash` does not match the recomputed hash.
 *  - `chain_link`       — row's `prev_hash` does not match the expected previous hash.
 *  - `row_count`        — number of rows in a segment does not match `checkpoint.row_count`.
 *  - `segment_hash`     — segment's final row_hash does not match `checkpoint.segment_hash`.
 *  - `checkpoint_chain` — checkpoint's `prev_checkpoint_hash` does not chain to the
 *                         previous checkpoint's `checkpoint_hash`.
 *  - `checkpoint_hash`  — the recomputed checkpoint hash does not match the stored value.
 *  - `checkpoint_signature` — a configured checkpoint signature is missing or invalid.
 *  - `prune_authorization` — a keyed pruned checkpoint lacks valid deletion authorization.
 *  - `succession_anchor` — version-bound succession evidence is missing or invalid.
 *  - `genesis`          — the genesis checkpoint's stored hash does not verify.
 *
 * @api
 */
final class AuditVerificationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $firstBrokenId,
        public readonly ?string $failureKind,
        public readonly string $message,
        public readonly int $segmentsVerified,
        public readonly int $rowsVerified,
        public readonly int $pendingUnsealedRows,
    ) {}

    /**
     * Build an intact result after all segments and rows verified successfully.
     */
    public static function intact(int $segments, int $rows, int $pending): self
    {
        return new self(
            ok: true,
            firstBrokenId: null,
            failureKind: null,
            message: sprintf(
                'Chain intact: %d segment(s), %d row(s) verified, %d pending.',
                $segments,
                $rows,
                $pending,
            ),
            segmentsVerified: $segments,
            rowsVerified: $rows,
            pendingUnsealedRows: $pending,
        );
    }

    /**
     * Build a broken result when tamper or corruption is detected.
     *
     * @param string $failureKind One of: row_content, chain_link, row_count,
     *                            segment_hash, checkpoint_chain, checkpoint_hash,
     *                            checkpoint_signature, prune_authorization,
     *                            succession_anchor, genesis.
     */
    public static function broken(
        int $firstBrokenId,
        string $failureKind,
        string $message,
        int $segments,
        int $rows,
        int $pending,
    ): self {
        return new self(
            ok: false,
            firstBrokenId: $firstBrokenId,
            failureKind: $failureKind,
            message: $message,
            segmentsVerified: $segments,
            rowsVerified: $rows,
            pendingUnsealedRows: $pending,
        );
    }
}
