<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Validation;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\Validation\ValidationReadLedgerInterface;
use Waaseyaa\Entity\Validation\ValidationReadReservationInterface;
use Waaseyaa\EntityStorage\Backend\StrictLedgerSchema;

/** Durable value-free ledger for the closed framework validator. @internal */
final readonly class DatabaseValidationReadLedger implements ValidationReadLedgerInterface
{
    public function __construct(private DatabaseInterface $database)
    {
        new StrictLedgerSchema($database)->ensure();
    }

    public function reserve(EntityStructure $subject, string $field): ValidationReadReservationInterface
    {
        $receiptId = bin2hex(random_bytes(16));
        $this->append($receiptId, 'reserved', null, json_encode([
            'kind' => 'entity_validation',
            'entity_type_id' => $subject->entityTypeId,
            'bundle_id' => $subject->bundleId,
            'entity_id' => $subject->id,
            'field_name' => $field,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new DatabaseValidationReadReservation(
            function (bool $success) use ($receiptId): void {
                $this->append(
                    $receiptId,
                    'finalized',
                    $success ? 'succeeded' : 'failed_closed',
                    null,
                );
            },
        );
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

/** @internal Exact one-shot reservation returned by DatabaseValidationReadLedger. */
final class DatabaseValidationReadReservation implements ValidationReadReservationInterface
{
    /** @var \Closure(bool): mixed */
    private readonly \Closure $finalizer;

    private bool $finalized = false;

    /** @param callable(bool): mixed $finalizer */
    public function __construct(callable $finalizer)
    {
        $this->finalizer = $finalizer(...);
    }

    public function finalize(bool $success): void
    {
        if ($this->finalized) {
            throw new \LogicException('A validation-read reservation may be finalized only once.');
        }
        ($this->finalizer)($success);
        $this->finalized = true;
    }
}
