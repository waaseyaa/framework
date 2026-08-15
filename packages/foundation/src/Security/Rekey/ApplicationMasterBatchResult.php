<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Non-secret evidence returned after one owner-atomic CAS batch. @api */
final readonly class ApplicationMasterBatchResult
{
    /** @var array<string, int<0, max>> */
    public array $purposeCountDeltas;

    /** @param array<array-key, mixed> $purposeCountDeltas */
    public function __construct(
        public string $nextCursor,
        public int $transitionedRecords,
        array $purposeCountDeltas,
        public string $batchCommitment,
    ) {
        if ($nextCursor === '' || strlen($nextCursor) > 512 || $transitionedRecords < 1) {
            throw new \InvalidArgumentException('Application-master batch results require a bounded cursor and positive row count.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $batchCommitment) !== 1) {
            throw new \InvalidArgumentException('Application-master batch commitments must be lowercase SHA-256 digests.');
        }
        foreach ($purposeCountDeltas as $purpose => $delta) {
            if (!is_string($purpose) || !is_int($delta) || $delta < 0) {
                throw new \InvalidArgumentException('Application-master batch purpose deltas must be non-negative integers.');
            }
        }
        /** @var array<string, int<0, max>> $purposeCountDeltas */
        $this->purposeCountDeltas = $purposeCountDeltas;
    }
}
