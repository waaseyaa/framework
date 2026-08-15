<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

/** Read-only runtime validation for the coordinator-owned audit schema. */
final class AuditEventSchemaHandler
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function ensureSchema(): void
    {
        SchemaRequirement::assertAvailable(
            $this->database,
            'audit_event',
            ['id', 'uuid', 'event_kind', 'account_uid', 'actor_uid', 'entity_type_id', 'entity_uuid', 'subject_uri', 'outcome', 'severity', 'attributes', 'created_at', 'row_hash', 'prev_hash'],
            'waaseyaa/audit:2026_08_12_000003_audit_runtime_schema',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            'privileged_read_ledger',
            ['id', 'receipt_id', 'event_type', 'outcome', 'descriptor', 'created_at'],
            'waaseyaa/audit:2026_08_12_000003_audit_runtime_schema',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            'audit_retention_policy',
            ['id', 'uuid', 'kind_pattern', 'older_than_seconds', 'action', 'created_at'],
            'waaseyaa/audit:2026_08_12_000003_audit_runtime_schema',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            'audit_checkpoint',
            ['id', 'uuid', 'segment_start_id', 'segment_end_id', 'row_count', 'segment_hash', 'prev_checkpoint_hash', 'checkpoint_hash', 'signature', 'hash_version', 'is_genesis', 'pruned', 'prune_authorization', 'created_at'],
            'waaseyaa/audit:2026_08_15_000006_audit_prune_authorization',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            'audit_checkpoint_succession',
            ['sequence', 'record_kind', 'format', 'purpose_id', 'algorithm', 'from_master_version', 'to_master_version', 'predecessor_key_id', 'successor_key_id', 'chain_digest', 'pruned_checkpoint_digest', 'pruned_checkpoint_count', 'checkpoint_count', 'terminal_checkpoint_id', 'terminal_checkpoint_uuid', 'terminal_segment_end_id', 'terminal_checkpoint_hash', 'rekey_request_digest', 'registry_checksum', 'rolled_back_sequence', 'signature', 'previous_ledger_hash', 'record_hash', 'created_at'],
            'waaseyaa/audit:2026_08_15_000007_audit_checkpoint_succession',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            'audit_checkpoint_succession_pruned',
            ['anchor_sequence', 'checkpoint_id', 'checkpoint_uuid', 'checkpoint_hash'],
            'waaseyaa/audit:2026_08_15_000007_audit_checkpoint_succession',
        );
    }
}
