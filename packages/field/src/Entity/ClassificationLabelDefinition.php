<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Entity representing a classification label definition.
 *
 * Each row defines one classification label available in the system.
 * The nine seed labels are shipped in
 * `packages/field/defaults/classification-labels.yaml` and imported
 * idempotently by the config-import subsystem keyed by `label_id` (NFR-005).
 *
 * Columns:
 *   - id                  (int, auto-increment PK)
 *   - uuid                (varchar, unique)
 *   - label_id            (varchar, unique natural key — e.g. 'public', 'internal')
 *   - display_name        (varchar — human-readable label)
 *   - confidentiality_level (int — ordinal for UI ordering; lower = less sensitive)
 *
 * @api
 */
#[ContentEntityType(
    id: 'classification_label_definition',
    label: 'Classification Label Definition',
    description: 'Defines the vocabulary of classification labels available in the system',
)]
final class ClassificationLabelDefinition extends ContentEntityBase
{
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

    /**
     * The natural-key identifier (e.g. 'public', 'internal', 'confidential').
     */
    public function getLabelId(): string
    {
        return (string) ($this->get('label_id') ?? '');
    }

    /**
     * Human-readable display name.
     */
    public function getDisplayName(): string
    {
        return (string) ($this->get('display_name') ?? '');
    }

    /**
     * Ordinal confidentiality level; lower values are less sensitive.
     * Used for ordering in the admin widget (ASC).
     */
    public function getConfidentialityLevel(): int
    {
        return (int) ($this->get('confidentiality_level') ?? 0);
    }
}
