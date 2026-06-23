<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Field type for entity classification labels.
 *
 * Contributes three columns to the host entity table:
 *   - classification_label          (varchar — the label_id from ClassificationLabelDefinition)
 *   - classification_inherited_from (varchar|null — parent entity UUID when inherited)
 *   - classification_overridden_at  (varchar|null — ISO-8601 timestamp when an explicit
 *                                    label overrides an inherited one)
 *
 * The column `classification_label` carries an index (declared in schemaFor()).
 *
 * Inheritance is resolved at field-resolution time via
 * {@see LabelInheritanceResolver::resolve()}. The resolved value is committed
 * back to the entity by {@see EntityLifecycleSubscriber} on PRE_SAVE.
 *
 * @api
 */
#[FieldType(
    id: 'classification_label',
    label: 'Classification Label',
    description: 'Stores the entity classification label with optional parent inheritance',
    category: 'classification',
    defaultCardinality: 1,
)]
final class ClassificationLabelFieldType extends AbstractFieldType
{
    // ---------------------------------------------------------------------------
    // FieldTypeInterface
    // ---------------------------------------------------------------------------

    /**
     * Base three-column storage schema.
     *
     * `schemaFor()` re-emits this with the `classification_label` index hint
     * annotated in the description (consumed by SqlSchemaHandler when it
     * applies indices, per entity-storage-two-axis convention).
     *
     * @return array<string, array{type: string, description?: string}>
     */
    public static function schema(): array
    {
        return [
            'classification_label' => [
                'type' => 'varchar',
                'description' => 'Classification label_id reference; indexed',
            ],
            'classification_inherited_from' => [
                'type' => 'varchar',
                'description' => 'Parent entity UUID when label is inherited; null when explicit',
            ],
            'classification_overridden_at' => [
                'type' => 'varchar',
                'description' => 'ISO-8601 timestamp when an inherited label was overridden',
            ],
        ];
    }

    /**
     * @return array<string, array{type: string, description?: string, index?: bool}>
     */
    public static function schemaFor(FieldDefinitionInterface $def): array
    {
        $base = static::schema();
        // Signal to SqlSchemaHandler that this column carries an index.
        $base['classification_label']['index'] = true;

        return $base;
    }

    // ---------------------------------------------------------------------------
    // JSON schema
    // ---------------------------------------------------------------------------

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'classification_label' => [
                    'type' => ['string', 'null'],
                    'description' => 'Classification label identifier',
                ],
                'classification_inherited_from' => [
                    'type' => ['string', 'null'],
                    'description' => 'Parent entity UUID when label is inherited',
                ],
                'classification_overridden_at' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO-8601 timestamp of last explicit override',
                    'format' => 'date-time',
                ],
            ],
        ];
    }

    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return static::jsonSchema();
    }

    // ---------------------------------------------------------------------------
    // Default value
    // ---------------------------------------------------------------------------

    public static function defaultValue(): mixed
    {
        return [
            'classification_label' => null,
            'classification_inherited_from' => null,
            'classification_overridden_at' => null,
        ];
    }

    public static function defaultSettings(): array
    {
        return [];
    }

    // ---------------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------------

    public function validate(): ConstraintViolationListInterface
    {
        return new ConstraintViolationList();
    }
}
