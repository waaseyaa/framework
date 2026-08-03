<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * A ledger that records nothing.
 *
 * This is the DEFAULT for surfaces that have not opted into durable auditing —
 * notably the public read-only MCP tier, whose documented behaviour stays
 * best-effort for compatibility.
 *
 * It must never be the default for a surface that mutates content. A write
 * surface wired to this ledger has no durability guarantee at all, which is why
 * `McpServiceProvider` fails closed (refuses the durable mode) rather than
 * silently substituting it.
 *
 * @api
 */
final class NullStrictAuditLedger implements StrictAuditLedgerInterface
{
    private int $counter = 0;

    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        return new StrictAuditReceipt(
            'null-' . (++$this->counter),
            $reservation->correlationId,
        );
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        // Intentionally empty.
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        // Intentionally empty.
    }
}
