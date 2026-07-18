<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Database\DatabaseInterface;

/** Shared additive schema required before any strict reserve operation. @internal */
final readonly class StrictLedgerSchema
{
    public function __construct(private DatabaseInterface $database) {}

    public function ensure(): void
    {
        $this->database->query('CREATE TABLE IF NOT EXISTS privileged_read_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            outcome VARCHAR(32) DEFAULT NULL,
            descriptor TEXT DEFAULT NULL,
            created_at VARCHAR(32) NOT NULL
        )');
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS privileged_read_ledger_receipt ON privileged_read_ledger (receipt_id, id)',
        );
        $this->database->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS privileged_read_ledger_once ON privileged_read_ledger (receipt_id, event_type)',
        );
    }
}
