<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Retention policy entity for the OCAP audit log.
 *
 * Each policy row describes a rule: events matching `kind_pattern` that are
 * older than `older_than_seconds` seconds are eligible for the `action`
 * (currently only `purge`).
 *
 * The `audit:prune` CLI command reads these policies and executes them
 * (FR-013, FR-014). Each execution is itself audit-logged with kind
 * {@see \Waaseyaa\Audit\Enum\AuditEventKind::AuditRetentionPruned} (FR-012).
 *
 * @api
 */
#[ContentEntityType(id: 'audit_retention_policy', label: 'Audit Retention Policy', description: 'OCAP audit log retention rule')]
#[ContentEntityKeys()]
final class AuditRetentionPolicy extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'audit_retention_policy',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Glob-style pattern matched against AuditEventKind values (e.g. `entity.*`, `*`).
     */
    public function getKindPattern(): string
    {
        return (string) ($this->get('kind_pattern') ?? '*');
    }

    /**
     * Events older than this many seconds are eligible for the action.
     */
    public function getOlderThanSeconds(): int
    {
        return (int) ($this->get('older_than_seconds') ?? 0);
    }

    /**
     * Action to apply: currently only `purge`.
     */
    public function getAction(): string
    {
        return (string) ($this->get('action') ?? 'purge');
    }

    public function getCreatedAt(): string
    {
        return (string) ($this->get('created_at') ?? '');
    }
}
