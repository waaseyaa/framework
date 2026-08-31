<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/**
 * A ledger that accepts reservations but cannot record a terminal refusal.
 *
 * The asymmetry is the point: it isolates the single-shot `record()` path, so a
 * test can prove that a terminal refusal the ledger rejects is visible to the
 * caller rather than masquerading as an ordinary, successfully-audited
 * `TOOL_NOT_FOUND`.
 */
final class RecordFailingStrictAuditLedger implements StrictAuditLedgerInterface
{
    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        return new StrictAuditReceipt('receipt-1', $reservation->correlationId);
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void {}

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        throw new StrictAuditLedgerException('terminal record offline');
    }
}
