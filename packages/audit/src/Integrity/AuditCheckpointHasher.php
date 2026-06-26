<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

/**
 * Canonical checkpoint hash computation shared by the checkpoint builder (WP2)
 * and the verifier (WP3).
 *
 * Keeping the formula in one place ensures the builder and verifier always
 * agree: a checkpoint is valid iff `checkpointHash(…)` recomputed from its
 * stored fields equals its stored `checkpoint_hash`.
 *
 * @api
 */
final class AuditCheckpointHasher
{
    /**
     * Computes the checkpoint hash from its defining inputs.
     *
     * The formula is:
     *   sha256( segmentStartId ":" segmentEndId ":" rowCount ":" segmentHash ":" prevCheckpointHash )
     *
     * All inputs are concatenated with ":" as delimiter. Integer inputs are
     * cast to string representations of their decimal values. The result is a
     * 64-character lowercase hex string.
     */
    public static function checkpointHash(
        int $segmentStartId,
        int $segmentEndId,
        int $rowCount,
        string $segmentHash,
        string $prevCheckpointHash,
    ): string {
        return hash(
            'sha256',
            $segmentStartId . ':' . $segmentEndId . ':' . $rowCount . ':' . $segmentHash . ':' . $prevCheckpointHash,
        );
    }
}
