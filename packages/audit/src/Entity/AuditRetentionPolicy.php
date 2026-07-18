<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Retention policy read model for the OCAP audit log.
 *
 * audit_retention_policy is deliberately NOT a registered content entity type —
 * it is a flat OCAP config table, not content. Each policy row describes a rule:
 * events matching `kind_pattern` older than `older_than_seconds` seconds are
 * eligible for the `action` (currently only `purge`). This class is a typed
 * accessor over a row; rows are accessed through the raw DatabaseInterface.
 *
 * @api
 */
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
