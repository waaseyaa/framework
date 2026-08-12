<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

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
        SchemaRequirement::assertAvailable(
            $this->database,
            'mcp_approval_event',
            ['id', 'request_id', 'event_type', 'request_key', 'principal_key', 'surface', 'operation', 'arguments_fingerprint', 'correlation_id', 'safe_arguments', 'expires_at', 'decision', 'operator_uid', 'decision_reason', 'receipt_id', 'created_at'],
            'waaseyaa/audit:2026_08_12_000005_approval_event_schema',
        );
    }
}
