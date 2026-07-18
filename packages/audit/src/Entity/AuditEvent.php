<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Append-only audit event read model.
 *
 * audit_event is deliberately NOT a registered content entity type — it is a
 * flat OCAP log table. Rows are appended via
 * {@see \Waaseyaa\Audit\Writer\AuditEventWriter::record()} through a raw,
 * insert-only DatabaseInterface write, and read back via
 * {@see \Waaseyaa\Audit\Query\AuditEventQuery}, which hydrates instances of this
 * class purely as a typed accessor over the row. Append-only immutability is
 * enforced structurally by {@see \Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase}
 * (throws \LogicException on any UPDATE/DELETE of audit_event); the only legal
 * deletion is the `audit:prune` retention purge via the raw DatabaseInterface.
 *
 * Schema columns: id, uuid, event_kind, account_uid, actor_uid (nullable
 * three-state actor), entity_type_id, entity_uuid, subject_uri, outcome,
 * severity, attributes (JSON), created_at.
 *
 * @api
 */
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

    /**
     * Flat value accessor for the append-only audit read model.
     *
     * audit_event is not a registered entity type, so bypass
     * TranslatableEntityTrait::get() — which resolves getEntityType() and would
     * throw for an unregistered type — and read the value bag directly. Audit
     * rows are never translatable and carry no field casts.
     */
    public function get(string $name): mixed
    {
        return parent::get($name);
    }

    public function getEventKind(): string
    {
        return (string) ($this->get('event_kind') ?? '');
    }

    public function getAccountUid(): int
    {
        return (int) ($this->get('account_uid') ?? 0);
    }

    /**
     * Authoritative three-state actor: account id N, `0` for the anonymous
     * account, or `null` for "no acting context".
     *
     * Missing column (pre-migration row), SQL NULL, and the `''` empty
     * sentinel all read as null — mirroring the {@see getEntityTypeId2()}
     * empty-sentinel precedent. {@see getAccountUid()} stays the legacy
     * `actor ?? 0` compat accessor.
     */
    public function getActorUid(): ?int
    {
        $val = $this->get('actor_uid');

        return $val !== null && $val !== '' ? (int) $val : null;
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
