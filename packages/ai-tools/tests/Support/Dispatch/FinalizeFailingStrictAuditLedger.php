<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/** Accepts reservations, then fails to finalize — the dangling-reservation case. */
final class FinalizeFailingStrictAuditLedger implements StrictAuditLedgerInterface
{
    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        return new StrictAuditReceipt('receipt-1', $reservation->correlationId);
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        throw new StrictAuditLedgerException('finalize offline');
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void {}
}
