<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

/** Read-only runtime validation for the coordinator-owned audit schema. */
final class AuditEventSchemaHandler
{
    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $hmacKey = null,
    ) {
        // Retained for API compatibility while CFG-01 migrates checkpoint key custody.
        unset($hmacKey);
    }

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
            ['id', 'uuid', 'segment_start_id', 'segment_end_id', 'row_count', 'segment_hash', 'prev_checkpoint_hash', 'checkpoint_hash', 'signature', 'hash_version', 'is_genesis', 'pruned', 'created_at'],
            'waaseyaa/audit:2026_08_12_000003_audit_runtime_schema',
        );
    }
}
