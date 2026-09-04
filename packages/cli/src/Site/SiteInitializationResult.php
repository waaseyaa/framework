<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\EvaluatedArtifactPlan;

/** @api */
final readonly class SiteInitializationResult
{
    /** @param list<string> $changedPaths @param list<ChangeReceipt> $receipts */
    public function __construct(
        public array $changedPaths,
        public bool $dryRun = false,
        public bool $recoveredInterruptedTransaction = false,
        public bool $cleanupPending = false,
        public bool $cancelled = false,
        public array $receipts = [],
        public ?ArtifactApplyResult $applyResult = null,
        public ?EvaluatedArtifactPlan $evaluation = null,
    ) {}
}
