<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;

/** The transient return transport for one controlled apply. @api */
final readonly class SiteInitializationInvocation
{
    /** @param list<ChangeReceipt> $receipts ordered outcomes, never a durable sink */
    public function __construct(
        public ArtifactApplyResult $result,
        public array $receipts = [],
    ) {}
}
