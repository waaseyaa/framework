<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * Handle to a durable reservation, returned by
 * {@see StrictAuditLedgerInterface::reserve()} and required to finalize it.
 *
 * @api
 */
final readonly class StrictAuditReceipt
{
    public function __construct(
        public string $id,
        public string $correlationId,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('A strict audit receipt cannot have an empty id.');
        }
        if ($correlationId === '') {
            throw new \InvalidArgumentException('A strict audit receipt requires a correlation id.');
        }
    }
}
