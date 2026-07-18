<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAuditReceipt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayFailure;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;

/** Durable reserve/finalize ledger for registrar-owned field-storage gateways. @api */
final class DatabaseStrictFieldStorageGatewayAudit implements StrictFieldStorageGatewayAuditInterface
{
    private DatabaseInterface $database;

    /** @var \WeakMap<FieldStorageGatewayAuditReceipt, string> */
    private \WeakMap $receiptIds;

    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database instanceof AppendOnlyAuditDatabase
            ? $database
            : new AppendOnlyAuditDatabase($database);
        $this->receiptIds = new \WeakMap();
    }

    public function reserve(FieldStorageGatewayAttempt $attempt): FieldStorageGatewayAuditReceipt
    {
        $receipt = new FieldStorageGatewayAuditReceipt($attempt);
        $receiptId = bin2hex(random_bytes(16));
        $this->append($receiptId, 'reserved', null, json_encode([
            'kind' => 'field_storage_gateway',
            'backend_id' => $attempt->backendId,
            'fingerprint' => $attempt->fingerprint,
            'operation' => $attempt->operation->value,
            'entity_type_id' => $attempt->entityTypeId,
            'entity_id' => $attempt->entityId,
            'field_name' => $attempt->fieldName,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->receiptIds[$receipt] = $receiptId;

        return $receipt;
    }

    public function succeed(FieldStorageGatewayAuditReceipt $receipt): void
    {
        $this->finalize($receipt, 'succeeded');
    }

    public function fail(FieldStorageGatewayAuditReceipt $receipt, FieldStorageGatewayFailure $failure): void
    {
        if ($failure->attempt !== $receipt->attempt) {
            throw new \LogicException('Field-storage failure must finalize its exact reserved attempt.');
        }
        $this->finalize($receipt, $failure->backendInvocationStarted ? 'failed_started' : 'failed_closed');
    }

    private function finalize(FieldStorageGatewayAuditReceipt $receipt, string $outcome): void
    {
        $receiptId = $this->receiptIds[$receipt]
            ?? throw new \LogicException('Only a live field-storage audit reservation may be finalized.');
        $this->append($receiptId, 'finalized', $outcome, null);
        unset($this->receiptIds[$receipt]);
    }

    private function append(string $receiptId, string $eventType, ?string $outcome, ?string $descriptor): void
    {
        $this->database->insert('privileged_read_ledger')->values([
            'receipt_id' => $receiptId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'descriptor' => $descriptor,
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.u'),
        ])->execute();
    }
}
