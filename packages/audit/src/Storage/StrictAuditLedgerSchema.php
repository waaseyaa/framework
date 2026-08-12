<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

/**
 * Additive schema required before any strict-audit reserve.
 *
 * Mirrors `Waaseyaa\EntityStorage\Backend\StrictLedgerSchema`, the equivalent
 * for the privileged-read ledger. The unique index on
 * `(receipt_id, event_type)` is load-bearing: it makes a double-finalize
 * impossible at the storage layer even if the application-level guard in
 * {@see \Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger::finalize()} were
 * bypassed or raced.
 *
 * @api
 */
final readonly class StrictAuditLedgerSchema
{
    public function __construct(private DatabaseInterface $database) {}

    public function ensure(): void
    {
        SchemaRequirement::assertAvailable(
            $this->database,
            'strict_audit_ledger',
            ['id', 'receipt_id', 'correlation_id', 'event_type', 'surface', 'operation', 'stage', 'outcome', 'actor_uid', 'descriptor', 'created_at'],
            'waaseyaa/audit:2026_08_12_000004_strict_audit_ledger_schema',
        );
    }
}
