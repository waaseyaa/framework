<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Durable batching for a closed, ordered set of query-capability probes. @api */
interface BatchStrictFieldStorageGatewayAuditInterface extends StrictFieldStorageGatewayAuditInterface
{
    /** @param list<FieldStorageGatewayAttempt> $attempts
     * @return list<FieldStorageGatewayAuditReceipt> Committed before return.
     */
    public function reserveMany(array $attempts): array;

    /** @param list<array{receipt: FieldStorageGatewayAuditReceipt, failure: ?FieldStorageGatewayFailure}> $outcomes */
    public function finalizeMany(array $outcomes): void;
}
