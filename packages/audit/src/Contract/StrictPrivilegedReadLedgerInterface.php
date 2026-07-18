<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/**
 * Synchronous reservation/finalization ledger for privileged reads.
 * Implementations must make reserve durable before any value is obtained.
 *
 * @api
 */
interface StrictPrivilegedReadLedgerInterface
{
    public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt;

    public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void;
}
