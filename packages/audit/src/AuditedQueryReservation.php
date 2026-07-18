<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;

/** Explicit one-shot finalizer for a reserved query execution. @api */
final class AuditedQueryReservation
{
    private bool $finalized = false;

    public function __construct(
        private readonly StrictPrivilegedReadLedgerInterface $ledger,
        private readonly PrivilegedReadReceipt $receipt,
    ) {}

    public function succeeded(): void
    {
        $this->finish(PrivilegedReadOutcome::Succeeded);
    }
    public function failed(): void
    {
        $this->finish(PrivilegedReadOutcome::Failed);
    }

    private function finish(PrivilegedReadOutcome $outcome): void
    {
        if ($this->finalized) {
            throw new \LogicException('A query reservation can be finalized only once.');
        }
        $this->ledger->finalize($this->receipt, $outcome);
        $this->finalized = true;
    }
}
