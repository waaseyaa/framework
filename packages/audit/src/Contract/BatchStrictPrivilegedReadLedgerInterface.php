<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/**
 * Transactional batch extension for related strict privileged-read evidence.
 *
 * Every reservation must be durable before the caller obtains any value, and
 * every finalization batch is all-or-nothing. Descriptors remain entity-scoped;
 * batching changes transaction count, not audit granularity.
 *
 * @api
 */
interface BatchStrictPrivilegedReadLedgerInterface extends StrictPrivilegedReadLedgerInterface
{
    /**
     * @param non-empty-list<PrivilegedReadDescriptor> $descriptors
     * @return non-empty-list<PrivilegedReadReceipt>
     */
    public function reserveMany(array $descriptors): array;

    /** @param non-empty-list<PrivilegedReadReceipt> $receipts */
    public function finalizeMany(array $receipts, PrivilegedReadOutcome $outcome): void;
}
