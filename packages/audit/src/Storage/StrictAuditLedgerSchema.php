<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;

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
        $this->database->query('CREATE TABLE IF NOT EXISTS strict_audit_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_id VARCHAR(64) NOT NULL,
            correlation_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            surface VARCHAR(64) NOT NULL,
            operation VARCHAR(191) NOT NULL,
            stage VARCHAR(64) NOT NULL,
            outcome VARCHAR(32) NOT NULL,
            actor_uid INTEGER DEFAULT NULL,
            descriptor TEXT DEFAULT NULL,
            created_at VARCHAR(32) NOT NULL
        )');
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS strict_audit_ledger_receipt ON strict_audit_ledger (receipt_id, id)',
        );
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS strict_audit_ledger_correlation ON strict_audit_ledger (correlation_id)',
        );
        // Storage-level double-finalize guard.
        $this->database->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS strict_audit_ledger_once ON strict_audit_ledger (receipt_id, event_type)',
        );
    }
}
