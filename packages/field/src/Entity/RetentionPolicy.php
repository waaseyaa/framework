<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Retention policy entity governing classification-driven data lifecycle.
 *
 * Each row declares one rule pairing a set of classification labels
 * (`applies_to`) with an action (`purge` | `redact` | `hold-flag`) and a
 * trigger (`age_based` | `event_based`). The scheduler reads these rows in
 * the per-action jobs declared by `ClassificationRetentionScheduleEntries`
 * (introduced in WP03).
 *
 * Columns:
 *   - id              (int, auto-increment PK)
 *   - uuid            (varchar, unique)
 *   - name            (varchar — operator-facing label)
 *   - applies_to      (TEXT JSON array of `label_id` strings; supports glob
 *                      e.g. `nation-*`)
 *   - action          (varchar enum — `purge` | `redact` | `hold-flag`)
 *   - trigger_kind    (varchar enum — `age_based` | `event_based`)
 *   - trigger_value   (varchar — ISO-8601 duration for age-based;
 *                      event-kind string for event-based)
 *   - exemptions      (TEXT JSON array of `entity_type:uuid` pairs that
 *                      bypass this policy)
 *   - created_at      (datetime)
 *
 * Sibling entity: `AuditRetentionPolicy` in `packages/audit` governs audit-log
 * retention only (kind-pattern + age). This entity extends the model to
 * non-audit entities via label-matching and the `redact` + `hold-flag`
 * actions. See spec.md §"Decisions pre-resolved" for the split rationale.
 *
 * @api
 */
#[ContentEntityType(
    id: 'retention_policy',
    label: 'Retention Policy',
    description: 'Classification-driven retention rule (purge, redact, or hold-flag) keyed by label set.',
    api: true,
)]
final class RetentionPolicy extends ContentEntityBase
{
    public const string ACTION_PURGE = 'purge';
    public const string ACTION_REDACT = 'redact';
    public const string ACTION_HOLD_FLAG = 'hold-flag';

    public const string TRIGGER_AGE_BASED = 'age_based';
    public const string TRIGGER_EVENT_BASED = 'event_based';

    #[Field(required: false, label: 'Name', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $name = '';

    /** @var list<string> */
    #[Field(required: false, label: 'Applies to', settings: ['subtype' => 'string_list'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public array $applies_to = [];

    #[Field(required: false, label: 'Action', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $action = '';

    #[Field(required: false, label: 'Trigger kind', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $trigger_kind = '';

    #[Field(required: false, label: 'Trigger value', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $trigger_value = '';

    /** @var list<string> */
    #[Field(required: false, label: 'Exemptions', settings: ['subtype' => 'string_list'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public array $exemptions = [];

    #[Field(type: 'integer', required: false, label: 'Created', settings: ['subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $created_at = null;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    public function getName(): string
    {
        return (string) ($this->get('name') ?? '');
    }

    /**
     * Label-id patterns this policy matches. Supports literal `label_id`
     * values and `prefix-*` globs.
     *
     * @return list<string>
     */
    public function getAppliesTo(): array
    {
        return $this->normalizeStringList($this->get('applies_to'));
    }

    /**
     * One of {@see ACTION_PURGE}, {@see ACTION_REDACT}, {@see ACTION_HOLD_FLAG}.
     */
    public function getAction(): string
    {
        return (string) ($this->get('action') ?? '');
    }

    /**
     * One of {@see TRIGGER_AGE_BASED}, {@see TRIGGER_EVENT_BASED}.
     */
    public function getTriggerKind(): string
    {
        return (string) ($this->get('trigger_kind') ?? '');
    }

    /**
     * ISO-8601 duration for age-based triggers (e.g. `P30D`), or an event
     * kind string for event-based triggers (e.g. `audit:access.denied`).
     */
    public function getTriggerValue(): string
    {
        return (string) ($this->get('trigger_value') ?? '');
    }

    /**
     * `entity_type:uuid` pairs that bypass this policy.
     *
     * @return list<string>
     */
    public function getExemptions(): array
    {
        return $this->normalizeStringList($this->get('exemptions'));
    }

    /**
     * Test whether the given concrete `label_id` is matched by this
     * policy's `applies_to` patterns.
     *
     * Glob support: a pattern ending in `*` is treated as a prefix match;
     * all other patterns are compared by literal equality.
     */
    public function matchesLabel(string $labelId): bool
    {
        foreach ($this->getAppliesTo() as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                if ($prefix === '' || str_starts_with($labelId, $prefix)) {
                    return true;
                }
            } elseif ($pattern === $labelId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the given `entity_type:uuid` pair is on this policy's
     * exemption list.
     */
    public function isExempt(string $entityType, string $uuid): bool
    {
        return in_array($entityType . ':' . $uuid, $this->getExemptions(), true);
    }

    /**
     * Coerce a raw column value (typically a JSON-encoded array on the
     * `applies_to` / `exemptions` columns, but also tolerate an already-
     * decoded array) into a list of non-empty strings.
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        $decoded = is_string($raw)
            ? (json_decode($raw, associative: true) ?? [])
            : ($raw ?? []);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
