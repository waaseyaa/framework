<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Verifies the tamper-evidence chain of all sealed audit_checkpoint segments.
 *
 * For each non-genesis checkpoint the verifier:
 *  1. Confirms the checkpoint chains correctly to its predecessor.
 *  2. Loads all audit_event rows in the segment and walks the row hash chain.
 *  3. Recomputes the segment_hash and checkpoint_hash from stored fields and
 *     compares them to the stored values.
 *
 * Returns an {@see AuditVerificationResult}: intact when everything matches,
 * broken with a machine-readable failure kind and the first broken ID when not.
 *
 * @api
 */
final class AuditChainVerifier
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function verify(): AuditVerificationResult
    {
        // ----------------------------------------------------------------
        // Step 1: load all checkpoints ordered by segment_end_id ASC.
        // ----------------------------------------------------------------
        $checkpoints = iterator_to_array(
            $this->database
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', [
                    'id',
                    'segment_start_id',
                    'segment_end_id',
                    'row_count',
                    'segment_hash',
                    'prev_checkpoint_hash',
                    'checkpoint_hash',
                    'is_genesis',
                    'pruned',
                ])
                ->orderBy('segment_end_id', 'ASC')
                ->execute(),
            false,
        );

        if ($checkpoints === []) {
            // No checkpoints at all — treat as intact with nothing verified.
            return AuditVerificationResult::intact(0, 0, $this->countUnsealedRows(0));
        }

        // ----------------------------------------------------------------
        // Step 2: verify genesis checkpoint.
        // ----------------------------------------------------------------
        $genesis = $checkpoints[0];
        $genesisEndId = (int) $genesis['segment_end_id'];

        $expectedGenesisHash = AuditCheckpointHasher::checkpointHash(
            (int) $genesis['segment_start_id'],
            $genesisEndId,
            (int) $genesis['row_count'],
            (string) $genesis['segment_hash'],
            (string) $genesis['prev_checkpoint_hash'],
        );

        if ($expectedGenesisHash !== (string) $genesis['checkpoint_hash']) {
            $this->logger->warning('audit.verify.genesis_hash_mismatch', [
                'stored'   => $genesis['checkpoint_hash'],
                'computed' => $expectedGenesisHash,
            ]);

            return AuditVerificationResult::broken(
                $genesisEndId,
                'genesis',
                sprintf(
                    'Genesis checkpoint hash mismatch at segment_end_id %d: stored=%s computed=%s',
                    $genesisEndId,
                    $genesis['checkpoint_hash'],
                    $expectedGenesisHash,
                ),
                0,
                0,
                $this->countUnsealedRows($genesisEndId),
            );
        }

        $prevCheckpointHash = (string) $genesis['checkpoint_hash'];
        $prevSegmentHash    = (string) $genesis['segment_hash'];
        $lastSealedId       = $genesisEndId;
        $segmentsVerified   = 0;
        $rowsVerified       = 0;

        // ----------------------------------------------------------------
        // Step 3: walk subsequent (non-genesis) checkpoints.
        // ----------------------------------------------------------------
        $remaining = array_slice($checkpoints, 1);

        foreach ($remaining as $checkpoint) {
            $segStartId = (int) $checkpoint['segment_start_id'];
            $segEndId   = (int) $checkpoint['segment_end_id'];
            $rowCount   = (int) $checkpoint['row_count'];
            $isPruned   = (bool) ($checkpoint['pruned'] ?? false);

            // 3a: checkpoint chain link — checked for BOTH pruned and normal segments.
            // A forged pruned checkpoint (with a wrong chain link) is still caught here.
            if ((string) $checkpoint['prev_checkpoint_hash'] !== $prevCheckpointHash) {
                $this->logger->warning('audit.verify.checkpoint_chain_mismatch', [
                    'segment_start_id' => $segStartId,
                    'stored_prev'      => $checkpoint['prev_checkpoint_hash'],
                    'expected_prev'    => $prevCheckpointHash,
                ]);

                return AuditVerificationResult::broken(
                    $segStartId,
                    'checkpoint_chain',
                    sprintf(
                        'Checkpoint chain broken at segment_start_id %d: prev_checkpoint_hash mismatch',
                        $segStartId,
                    ),
                    $segmentsVerified,
                    $rowsVerified,
                    $this->countUnsealedRows($lastSealedId),
                );
            }

            // 3a2: recompute checkpoint_hash for pruned segments as well — a
            // forged pruned checkpoint that alters segment_hash or checkpoint_hash
            // is detected here before we skip row-level checks.
            if ($isPruned) {
                $recomputedPruned = AuditCheckpointHasher::checkpointHash(
                    $segStartId,
                    $segEndId,
                    $rowCount,
                    (string) $checkpoint['segment_hash'],
                    (string) $checkpoint['prev_checkpoint_hash'],
                );

                if ($recomputedPruned !== (string) $checkpoint['checkpoint_hash']) {
                    $this->logger->warning('audit.verify.checkpoint_hash_mismatch', [
                        'segment_end_id' => $segEndId,
                        'stored'         => $checkpoint['checkpoint_hash'],
                        'computed'       => $recomputedPruned,
                        'pruned'         => true,
                    ]);

                    return AuditVerificationResult::broken(
                        $segEndId,
                        'checkpoint_hash',
                        sprintf(
                            'Pruned checkpoint hash mismatch at segment_end_id %d: recomputed hash does not match stored value',
                            $segEndId,
                        ),
                        $segmentsVerified,
                        $rowsVerified,
                        $this->countUnsealedRows($lastSealedId),
                    );
                }

                // Pruned segment: rows are legitimately gone — skip row-level checks.
                // Advance the chain anchors so the NEXT segment's first row still
                // chains correctly (its prev_hash == this segment's segment_hash).
                $prevCheckpointHash = (string) $checkpoint['checkpoint_hash'];
                $prevSegmentHash    = (string) $checkpoint['segment_hash'];
                $lastSealedId       = $segEndId;
                ++$segmentsVerified;

                continue;
            }

            // 3b: load the segment's rows (normal — not pruned).
            $rows = iterator_to_array(
                $this->database
                    ->select('audit_event')
                    ->fields('audit_event', [
                        'id', 'uuid', 'event_kind', 'account_uid', 'actor_uid',
                        'entity_type_id', 'entity_uuid', 'subject_uri', 'outcome',
                        'severity', 'attributes', 'created_at',
                        'row_hash', 'prev_hash',
                    ])
                    ->condition('id', $segStartId, '>=')
                    ->condition('id', $segEndId, '<=')
                    ->orderBy('id', 'ASC')
                    ->execute(),
                false,
            );

            // 3c: row count check.
            if (count($rows) !== $rowCount) {
                $this->logger->warning('audit.verify.row_count_mismatch', [
                    'segment_start_id' => $segStartId,
                    'stored_count'     => $rowCount,
                    'actual_count'     => count($rows),
                ]);

                return AuditVerificationResult::broken(
                    $segStartId,
                    'row_count',
                    sprintf(
                        'Row count mismatch in segment %d–%d: stored=%d actual=%d',
                        $segStartId,
                        $segEndId,
                        $rowCount,
                        count($rows),
                    ),
                    $segmentsVerified,
                    $rowsVerified,
                    $this->countUnsealedRows($lastSealedId),
                );
            }

            // 3d: walk the row hash chain.
            $expectedPrev = $prevSegmentHash;

            foreach ($rows as $row) {
                $rowId = (int) $row['id'];

                // Chain link: stored prev_hash must equal expected.
                if ((string) $row['prev_hash'] !== $expectedPrev) {
                    $this->logger->warning('audit.verify.chain_link_broken', [
                        'row_id'        => $rowId,
                        'stored_prev'   => $row['prev_hash'],
                        'expected_prev' => $expectedPrev,
                    ]);

                    return AuditVerificationResult::broken(
                        $rowId,
                        'chain_link',
                        sprintf('Row %d: prev_hash does not match expected chain link', $rowId),
                        $segmentsVerified,
                        $rowsVerified,
                        $this->countUnsealedRows($lastSealedId),
                    );
                }

                // Content integrity: recompute row_hash and compare.
                $computed = AuditEventCanonicalizer::rowHash($row, (string) $row['prev_hash']);
                if ($computed !== (string) $row['row_hash']) {
                    $this->logger->warning('audit.verify.row_content_tampered', [
                        'row_id'   => $rowId,
                        'stored'   => $row['row_hash'],
                        'computed' => $computed,
                    ]);

                    return AuditVerificationResult::broken(
                        $rowId,
                        'row_content',
                        sprintf('Row %d: row_hash mismatch — content may have been tampered', $rowId),
                        $segmentsVerified,
                        $rowsVerified,
                        $this->countUnsealedRows($lastSealedId),
                    );
                }

                $expectedPrev = (string) $row['row_hash'];
                ++$rowsVerified;
            }

            // 3e: segment hash = last row's row_hash.
            if ($expectedPrev !== (string) $checkpoint['segment_hash']) {
                $this->logger->warning('audit.verify.segment_hash_mismatch', [
                    'segment_end_id' => $segEndId,
                    'stored'         => $checkpoint['segment_hash'],
                    'computed'       => $expectedPrev,
                ]);

                return AuditVerificationResult::broken(
                    $segEndId,
                    'segment_hash',
                    sprintf(
                        'Segment hash mismatch at segment_end_id %d: stored segment_hash does not match last row_hash',
                        $segEndId,
                    ),
                    $segmentsVerified,
                    $rowsVerified,
                    $this->countUnsealedRows($lastSealedId),
                );
            }

            // 3f: recompute checkpoint_hash from stored fields.
            $recomputed = AuditCheckpointHasher::checkpointHash(
                $segStartId,
                $segEndId,
                $rowCount,
                (string) $checkpoint['segment_hash'],
                (string) $checkpoint['prev_checkpoint_hash'],
            );

            if ($recomputed !== (string) $checkpoint['checkpoint_hash']) {
                $this->logger->warning('audit.verify.checkpoint_hash_mismatch', [
                    'segment_end_id' => $segEndId,
                    'stored'         => $checkpoint['checkpoint_hash'],
                    'computed'       => $recomputed,
                ]);

                return AuditVerificationResult::broken(
                    $segEndId,
                    'checkpoint_hash',
                    sprintf(
                        'Checkpoint hash mismatch at segment_end_id %d: recomputed hash does not match stored value',
                        $segEndId,
                    ),
                    $segmentsVerified,
                    $rowsVerified,
                    $this->countUnsealedRows($lastSealedId),
                );
            }

            // 3g: advance tracking.
            $prevCheckpointHash = (string) $checkpoint['checkpoint_hash'];
            $prevSegmentHash    = (string) $checkpoint['segment_hash'];
            $lastSealedId       = $segEndId;
            ++$segmentsVerified;
        }

        // ----------------------------------------------------------------
        // Step 4: count unsealed rows (id > lastSealedId).
        // ----------------------------------------------------------------
        $pendingUnsealedRows = $this->countUnsealedRows($lastSealedId);

        return AuditVerificationResult::intact($segmentsVerified, $rowsVerified, $pendingUnsealedRows);
    }

    private function countUnsealedRows(int $lastSealedId): int
    {
        $rows = iterator_to_array(
            $this->database
                ->select('audit_event')
                ->fields('audit_event', ['id'])
                ->condition('id', $lastSealedId, '>')
                ->execute(),
            false,
        );

        return count($rows);
    }
}
