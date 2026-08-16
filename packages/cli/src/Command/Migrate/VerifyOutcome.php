<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * The full verify result: a list of per-row outcomes plus the summary.
 */
final readonly class VerifyOutcome
{
    /**
     * @param list<VerifyResultRow> $rows
     */
    public function __construct(
        public array $rows,
        public VerifySummary $summary,
        public VerifyAuthorityResult $authority,
    ) {}
}
