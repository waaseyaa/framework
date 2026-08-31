<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/**
 * A ledger that records every call into an ordered journal.
 *
 * Deliberately a real class rather than a PHPUnit mock: the property under test
 * is ORDER — reserve strictly before the tool runs, finalize strictly after —
 * and an expectation-based mock states that far less legibly than a shared
 * journal does.
 */
final class RecordingStrictAuditLedger implements StrictAuditLedgerInterface
{
    /** @var list<array<string, mixed>> */
    public array $journal = [];

    private int $counter = 0;

    /** @param ?\ArrayObject<int, string> $sharedOrder Cross-object ordering journal. */
    public function __construct(private readonly ?\ArrayObject $sharedOrder = null) {}

    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        $this->journal[] = ['call' => 'reserve', 'reservation' => $reservation];
        $this->sharedOrder?->append('reserve');

        return new StrictAuditReceipt('receipt-' . (++$this->counter), $reservation->correlationId);
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        $this->journal[] = ['call' => 'finalize', 'receipt' => $receipt, 'stage' => $stage, 'metadata' => $metadata];
        $this->sharedOrder?->append('finalize');
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        $this->journal[] = ['call' => 'record', 'reservation' => $reservation, 'stage' => $stage];
        $this->sharedOrder?->append('record');
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(static fn(array $entry): string => (string) $entry['call'], $this->journal);
    }

    /** @return list<array<string, mixed>> */
    public function entriesFor(string $call): array
    {
        return array_values(array_filter(
            $this->journal,
            static fn(array $entry): bool => $entry['call'] === $call,
        ));
    }
}
