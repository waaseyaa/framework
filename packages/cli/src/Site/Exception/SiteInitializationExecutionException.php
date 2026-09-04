<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Exception;

use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;

/** A terminated execution failure with its transient outcome receipts. @api */
final class SiteInitializationExecutionException extends \RuntimeException
{
    /** @param list<ChangeReceipt> $receipts */
    public function __construct(
        \Throwable $previous,
        public readonly array $receipts = [],
        public readonly ?ArtifactApplyResult $applyResult = null,
    ) {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
