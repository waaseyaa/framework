<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Synchronous reserve/finalize audit required by every registered V2 gateway. @api */
interface StrictFieldStorageGatewayAuditInterface
{
    public function reserve(FieldStorageGatewayAttempt $attempt): FieldStorageGatewayAuditReceipt;

    public function succeed(FieldStorageGatewayAuditReceipt $receipt): void;

    public function fail(FieldStorageGatewayAuditReceipt $receipt, FieldStorageGatewayFailure $failure): void;
}
