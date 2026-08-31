<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/** A real ledger whose `reserve()` cannot make the attempt durable. */
final class UnavailableStrictAuditLedger implements StrictAuditLedgerInterface
{
    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        throw new StrictAuditLedgerException('ledger offline');
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void {}

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void {}
}
