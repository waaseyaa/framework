<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\ReadModel;

use Waaseyaa\Entity\FieldReadLevel;

/** Field-read metadata for deliberately unregistered flat audit rows. @api */
final class AuditReadModelDefinitionRegistry
{
    /** @var array<string, array<string, FieldReadLevel>> */
    private const array DEFINITIONS = [
        'audit_event' => ['id' => FieldReadLevel::Public, 'uuid' => FieldReadLevel::Public, 'event_kind' => FieldReadLevel::Internal, 'account_uid' => FieldReadLevel::Internal, 'actor_uid' => FieldReadLevel::Internal, 'entity_type_id' => FieldReadLevel::Internal, 'entity_uuid' => FieldReadLevel::Internal, 'subject_uri' => FieldReadLevel::Internal, 'outcome' => FieldReadLevel::Internal, 'severity' => FieldReadLevel::Internal, 'attributes' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal, 'row_hash' => FieldReadLevel::Internal, 'prev_hash' => FieldReadLevel::Internal],
        'audit_retention_policy' => ['id' => FieldReadLevel::Public, 'uuid' => FieldReadLevel::Public, 'kind_pattern' => FieldReadLevel::Internal, 'older_than_seconds' => FieldReadLevel::Internal, 'action' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal],
        'audit_checkpoint' => ['id' => FieldReadLevel::Public, 'uuid' => FieldReadLevel::Public, 'segment_start_id' => FieldReadLevel::Internal, 'segment_end_id' => FieldReadLevel::Internal, 'row_count' => FieldReadLevel::Internal, 'segment_hash' => FieldReadLevel::Internal, 'prev_checkpoint_hash' => FieldReadLevel::Internal, 'checkpoint_hash' => FieldReadLevel::Internal, 'signature' => FieldReadLevel::Internal, 'hash_version' => FieldReadLevel::Internal, 'is_genesis' => FieldReadLevel::Internal, 'pruned' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal],
        'privileged_read_ledger' => ['id' => FieldReadLevel::Public, 'receipt_id' => FieldReadLevel::Internal, 'event_type' => FieldReadLevel::Internal, 'outcome' => FieldReadLevel::Internal, 'descriptor' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal],
        'strict_audit_ledger' => ['id' => FieldReadLevel::Public, 'receipt_id' => FieldReadLevel::Internal, 'correlation_id' => FieldReadLevel::Internal, 'event_type' => FieldReadLevel::Internal, 'surface' => FieldReadLevel::Internal, 'operation' => FieldReadLevel::Internal, 'stage' => FieldReadLevel::Internal, 'outcome' => FieldReadLevel::Internal, 'actor_uid' => FieldReadLevel::Internal, 'descriptor' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal],
        'mcp_approval_event' => ['id' => FieldReadLevel::Public, 'request_id' => FieldReadLevel::Internal, 'event_type' => FieldReadLevel::Internal, 'request_key' => FieldReadLevel::Internal, 'principal_key' => FieldReadLevel::Internal, 'surface' => FieldReadLevel::Internal, 'operation' => FieldReadLevel::Internal, 'arguments_fingerprint' => FieldReadLevel::Internal, 'correlation_id' => FieldReadLevel::Internal, 'safe_arguments' => FieldReadLevel::Internal, 'expires_at' => FieldReadLevel::Internal, 'decision' => FieldReadLevel::Internal, 'operator_uid' => FieldReadLevel::Internal, 'decision_reason' => FieldReadLevel::Internal, 'receipt_id' => FieldReadLevel::Internal, 'created_at' => FieldReadLevel::Internal],
    ];

    public function level(string $readModel, string $field): ?FieldReadLevel
    {
        return self::DEFINITIONS[$readModel][$field] ?? null;
    }

    /** @return array<string, array<string, FieldReadLevel>> */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }
}
