<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/**
 * A ledger that fails with something other than `StrictAuditLedgerException`.
 *
 * It stands in for the class of failure that made the original defect
 * reachable: `StrictAuditReservation` rejecting a value with
 * `\InvalidArgumentException`, which is not a ledger exception at all. A catch
 * narrowed to the ledger's own type would let it escape `dispatch()` and break
 * `ToolDispatcherInterface`'s contract that a caller-caused failure never
 * throws.
 */
final class NonLedgerExceptionStrictAuditLedger implements StrictAuditLedgerInterface
{
    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        throw new \InvalidArgumentException('not a ledger exception');
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void {}

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        throw new \InvalidArgumentException('not a ledger exception');
    }
}
