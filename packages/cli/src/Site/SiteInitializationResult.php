<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

/** @api */
final readonly class SiteInitializationResult
{
    /** @param list<string> $changedPaths */
    public function __construct(
        public array $changedPaths,
        public bool $dryRun = false,
        public bool $recoveredInterruptedTransaction = false,
        public bool $cleanupPending = false,
        public bool $cancelled = false,
    ) {}
}
