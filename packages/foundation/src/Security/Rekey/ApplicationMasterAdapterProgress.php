<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Restart-safe joint-adapter inventory and cursor projection. @api */
final readonly class ApplicationMasterAdapterProgress
{
    /**
     * @param list<string> $purposeIds
     * @param array<string, int> $purposeCounts
     */
    public function __construct(
        public string $requestId,
        public string $adapterId,
        public array $purposeIds,
        public string $snapshotToken,
        public int $totalRecords,
        public ?string $cursor,
        public int $transitionedRecords,
        public array $purposeCounts,
        public string $batchChainHash,
        public string $status,
        public int $revision,
        public int $unresolvedFailures,
    ) {}
}
