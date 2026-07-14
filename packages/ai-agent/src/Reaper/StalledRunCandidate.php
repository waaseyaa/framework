<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Reaper;

use Waaseyaa\AI\Agent\Enum\RunStatus;

/** Immutable source-state snapshot carried from reaper selection into its CAS. @internal */
final readonly class StalledRunCandidate
{
    public function __construct(
        public string $id,
        public RunStatus $sourceStatus,
        public ?string $queuedAt,
        public ?string $startedAt,
        public ?string $pendingApprovalCallId,
        public ?string $approvalExpiresAt,
    ) {
        if ($sourceStatus->isTerminal()) {
            throw new \InvalidArgumentException('A stalled-run candidate must have a non-terminal source status.');
        }
    }
}
