<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Additive schema for the durable operation-approval event log (#2177 F1).
 *
 * Mirrors {@see StrictAuditLedgerSchema}. One row per append-only event
 * (`requested` / `decided` / `consumed`); the unique index on
 * `(request_id, event_type)` is load-bearing in both write paths: it makes a
 * second decision and a second consumption impossible at the storage layer,
 * even if the application-level guards in
 * {@see \Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore} were bypassed
 * or raced.
 *
 * Column use by event type: `safe_arguments` and `expires_at` are populated on
 * `requested`; `decision`, `operator_uid` and the optional single-line
 * `decision_reason` on `decided`; `receipt_id` (the
 * strict-ledger receipt of the consuming execution) on `consumed`.
 * `correlation_id` carries the original request's correlation on `requested`
 * and `decided`, and the consuming retry's correlation on `consumed`. The
 * tuple columns are repeated on every event so each row is self-describing.
 *
 * @api
 */
final readonly class ApprovalEventSchema
{
    public function __construct(private DatabaseInterface $database) {}

    public function ensure(): void
    {
        $this->database->query('CREATE TABLE IF NOT EXISTS mcp_approval_event (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            request_key VARCHAR(64) NOT NULL,
            principal_key VARCHAR(191) NOT NULL,
            surface VARCHAR(64) NOT NULL,
            operation VARCHAR(191) NOT NULL,
            arguments_fingerprint VARCHAR(64) NOT NULL,
            correlation_id VARCHAR(64) NOT NULL,
            safe_arguments TEXT DEFAULT NULL,
            expires_at VARCHAR(32) DEFAULT NULL,
            decision VARCHAR(16) DEFAULT NULL,
            operator_uid INTEGER DEFAULT NULL,
            decision_reason VARCHAR(500) DEFAULT NULL,
            receipt_id VARCHAR(64) DEFAULT NULL,
            created_at VARCHAR(32) NOT NULL
        )');
        // Storage-level once-only guard for decision AND consumption.
        $this->database->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS mcp_approval_event_once ON mcp_approval_event (request_id, event_type)',
        );
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS mcp_approval_event_request_key ON mcp_approval_event (request_key, id)',
        );
        $this->database->query(
            'CREATE INDEX IF NOT EXISTS mcp_approval_event_correlation ON mcp_approval_event (correlation_id)',
        );
    }
}
