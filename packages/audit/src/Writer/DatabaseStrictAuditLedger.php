<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;

/**
 * Durable, fail-closed reserve/finalize ledger backed by the append-only audit
 * database.
 *
 * The sibling of {@see DatabaseStrictPrivilegedReadLedger}, which established
 * this pattern for privileged reads; this one serves request pipelines that
 * mutate (currently only the MCP write tier). Both write through
 * {@see AppendOnlyAuditDatabase}, so a row here can be appended but never
 * updated or deleted through this path.
 *
 * ## Why the write commits immediately
 *
 * `reserve()` deliberately does NOT participate in any caller transaction. Its
 * whole purpose is to be durable *before* the caller acts, so that a crash
 * during the side effect still leaves evidence that the attempt was made. A
 * reservation that committed together with the mutation would vanish with it.
 *
 * The consequence is stated plainly in
 * {@see StrictAuditLedgerInterface}: this gives "no side effect without a
 * durable record of the attempt", NOT atomicity between the side effect and the
 * outcome record. A crash in between leaves a dangling reservation.
 *
 * @api
 */
final readonly class DatabaseStrictAuditLedger implements StrictAuditLedgerInterface
{
    private DatabaseInterface $database;

    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database instanceof AppendOnlyAuditDatabase
            ? $database
            : new AppendOnlyAuditDatabase($database);
    }

    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        $receipt = new StrictAuditReceipt(bin2hex(random_bytes(16)), $reservation->correlationId);

        try {
            $this->append($receipt, 'reserved', AuditStage::RequestAccepted, $reservation, []);
        } catch (\Throwable $e) {
            throw new StrictAuditLedgerException(
                'Strict audit reservation could not be made durable; the operation must not proceed.',
                0,
                $e,
            );
        }

        return $receipt;
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        $transaction = $this->database->transaction('strict-audit-finalize');

        try {
            $events = iterator_to_array($this->database->query(
                'SELECT event_type FROM strict_audit_ledger WHERE receipt_id = :receipt ORDER BY id',
                ['receipt' => $receipt->id],
            ));

            // Exactly one 'reserved' row and nothing else. Guards both directions:
            // finalizing something never reserved, and finalizing twice. A repeat
            // is a programming error, not a retry — the side effect already ran.
            if (count($events) !== 1 || ($events[0]['event_type'] ?? null) !== 'reserved') {
                throw new \LogicException(
                    'Only a durable, unfinished strict-audit reservation may be finalized.',
                );
            }

            $this->appendFinalization($receipt, $stage, $metadata);
            $transaction->commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
                // The finalize row is what matters; a failed rollback must not
                // mask the original cause below.
            }

            if ($e instanceof \LogicException) {
                throw $e;
            }

            throw new StrictAuditLedgerException(
                'Strict audit outcome could not be made durable.',
                0,
                $e,
            );
        }
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        $receipt = new StrictAuditReceipt(bin2hex(random_bytes(16)), $reservation->correlationId);

        try {
            $this->append($receipt, 'recorded', $stage, $reservation, []);
        } catch (\Throwable $e) {
            throw new StrictAuditLedgerException(
                'Strict audit record could not be made durable.',
                0,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function append(
        StrictAuditReceipt $receipt,
        string $eventType,
        AuditStage $stage,
        StrictAuditReservation $reservation,
        array $metadata,
    ): void {
        $this->database->insert('strict_audit_ledger')->values([
            'receipt_id'     => $receipt->id,
            'correlation_id' => $reservation->correlationId,
            'event_type'     => $eventType,
            'surface'        => $reservation->surface,
            'operation'      => $reservation->operation,
            'stage'          => $stage->value,
            'outcome'        => $stage->outcome(),
            'actor_uid'      => $reservation->actorUid,
            'descriptor'     => $this->encode($reservation, $metadata),
            'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /**
     * The finalization row repeats surface/operation/actor from the reservation
     * so the outcome row is self-describing — a reader does not have to join
     * back to the reservation to know what failed.
     *
     * @param array<string, mixed> $metadata
     */
    private function appendFinalization(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata): void
    {
        $reserved = iterator_to_array($this->database->query(
            'SELECT surface, operation, actor_uid FROM strict_audit_ledger '
            . 'WHERE receipt_id = :receipt AND event_type = :type ORDER BY id',
            ['receipt' => $receipt->id, 'type' => 'reserved'],
        ));
        $row = (array) ($reserved[0] ?? []);

        $this->database->insert('strict_audit_ledger')->values([
            'receipt_id'     => $receipt->id,
            'correlation_id' => $receipt->correlationId,
            'event_type'     => 'finalized',
            'surface'        => (string) ($row['surface'] ?? ''),
            'operation'      => (string) ($row['operation'] ?? ''),
            'stage'          => $stage->value,
            'outcome'        => $stage->outcome(),
            'actor_uid'      => $row['actor_uid'] ?? null,
            'descriptor'     => json_encode(['metadata' => $metadata], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s.u'),
        ])->execute();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encode(StrictAuditReservation $reservation, array $metadata): string
    {
        return json_encode([
            'safe_arguments' => $reservation->safeArguments,
            'metadata'       => $reservation->metadata + $metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
