<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

/** Verified boundary between anchor-pinned and directly key-verifiable checkpoints. @internal */
final readonly class AuditSuccessionVerificationWindow
{
    /** @param array<int, array{uuid: string, hash: string}> $prunedEvidence */
    public function __construct(
        public int $pinnedThroughCheckpointId,
        public int $signingVersion,
        private array $prunedEvidence,
    ) {}

    public function authorizesPrune(int $checkpointId, string $uuid, string $checkpointHash): bool
    {
        $evidence = $this->prunedEvidence[$checkpointId] ?? null;

        return $evidence !== null
            && hash_equals($evidence['uuid'], $uuid)
            && hash_equals($evidence['hash'], $checkpointHash);
    }
}
