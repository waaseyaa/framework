<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Opaque strict-audit reservation receipt. @api */
final readonly class FieldStorageGatewayAuditReceipt
{
    public function __construct(public FieldStorageGatewayAttempt $attempt) {}

    public function __serialize(): array
    {
        throw new \LogicException('Field-storage audit receipts cannot be serialized.');
    }
}
