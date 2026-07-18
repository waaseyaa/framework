<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Support;

use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAuditReceipt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayFailure;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;

final class PermissiveFieldStorageGatewayAudit implements StrictFieldStorageGatewayAuditInterface
{
    public function reserve(FieldStorageGatewayAttempt $attempt): FieldStorageGatewayAuditReceipt
    {
        return new FieldStorageGatewayAuditReceipt($attempt);
    }

    public function succeed(FieldStorageGatewayAuditReceipt $receipt): void {}

    public function fail(FieldStorageGatewayAuditReceipt $receipt, FieldStorageGatewayFailure $failure): void {}
}
