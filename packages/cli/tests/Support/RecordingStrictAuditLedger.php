<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Support;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/** An in-memory {@see StrictAuditLedgerInterface} that records every call for test assertions. */
final class RecordingStrictAuditLedger implements StrictAuditLedgerInterface
{
    /** @var list<array{type: string, reservation?: StrictAuditReservation, stage: AuditStage, metadata?: array<string, mixed>}> */
    public array $calls = [];

    private int $nextId = 1;

    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        $this->calls[] = ['type' => 'reserve', 'reservation' => $reservation, 'stage' => AuditStage::RequestAccepted];

        return new StrictAuditReceipt('receipt-' . $this->nextId++, $reservation->correlationId);
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        $this->calls[] = ['type' => 'finalize', 'stage' => $stage, 'metadata' => $metadata, 'receipt' => $receipt];
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        $this->calls[] = ['type' => 'record', 'reservation' => $reservation, 'stage' => $stage];
    }
}
