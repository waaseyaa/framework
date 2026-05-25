<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Append-only audit event entity.
 *
 * Instances are created via {@see \Waaseyaa\Audit\Writer\AuditEventWriter::record()}
 * and MUST NOT be updated or deleted through normal entity channels.
 * The {@see \Waaseyaa\Audit\Storage\AppendOnlyDriverGuard} enforces this at the
 * storage driver layer by throwing \LogicException on update/delete calls.
 *
 * Schema columns: id, uuid, event_kind, account_uid, entity_type_id, entity_uuid,
 * subject_uri, outcome, severity, attributes (JSON), created_at.
 *
 * @api
 */
#[ContentEntityType(id: 'audit_event', label: 'Audit Event', description: 'OCAP append-only audit log entry')]
#[ContentEntityKeys()]
final class AuditEvent extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'audit_event',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    public function getEventKind(): string
    {
        return (string) ($this->get('event_kind') ?? '');
    }

    public function getAccountUid(): int
    {
        return (int) ($this->get('account_uid') ?? 0);
    }

    public function getSubjectUri(): string
    {
        return (string) ($this->get('subject_uri') ?? '');
    }

    public function getOutcome(): string
    {
        return (string) ($this->get('outcome') ?? 'allowed');
    }

    public function getSeverity(): string
    {
        return (string) ($this->get('severity') ?? 'info');
    }

    public function getEntityTypeId2(): ?string
    {
        $val = $this->get('entity_type_id');
        return $val !== null && $val !== '' ? (string) $val : null;
    }

    public function getEntityUuid(): ?string
    {
        $val = $this->get('entity_uuid');
        return $val !== null && $val !== '' ? (string) $val : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $raw = $this->get('attributes');
        if (is_string($raw)) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                return [];
            }
        }
        return is_array($raw) ? $raw : [];
    }

    public function getCreatedAt(): string
    {
        return (string) ($this->get('created_at') ?? '');
    }
}
