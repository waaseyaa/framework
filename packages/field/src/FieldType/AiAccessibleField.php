<?php

declare(strict_types=1);

namespace Waaseyaa\Field\FieldType;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldItemBase;

/**
 * Field type for the per-file AI accessibility tri-state toggle.
 *
 * Persisted values: 'yes', 'no', 'inherit'. Default 'inherit' (per C-004).
 *
 * - 'yes'     — AI tools may read this file.
 * - 'no'      — AI tools may not read this file.
 * - 'inherit' — Defer to the entity's classification label.
 *               Until M-A4 ships, 'inherit' resolves to 'yes' for
 *               unclassified entities (access-preserving default).
 *
 * Storage: VARCHAR(8) — sufficient for the longest value ('inherit').
 *
 * @api
 */
#[FieldType(
    id: 'ai_accessible',
    label: 'AI Accessibility',
    description: 'Controls whether AI tools may read this file (yes/no/inherit).',
    category: 'general',
    defaultCardinality: 1,
)]
final class AiAccessibleField extends FieldItemBase
{
    /** @var list<string> */
    private const VALID_VALUES = ['yes', 'no', 'inherit'];

    public static function propertyDefinitions(): array
    {
        return [
            'value' => 'string',
        ];
    }

    public static function mainPropertyName(): string
    {
        return 'value';
    }

    public static function schema(): array
    {
        return [
            'value' => ['type' => 'varchar', 'length' => 8, 'not null' => true, 'default' => 'inherit'],
        ];
    }

    public static function schemaFor(FieldDefinitionInterface $def): array
    {
        return static::schema();
    }

    public static function defaultSettings(): array
    {
        return [];
    }

    public static function defaultValue(): mixed
    {
        return 'inherit';
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => self::VALID_VALUES,
        ];
    }

    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return static::jsonSchema();
    }

    /**
     * Validate that the stored value is one of the allowed literals.
     *
     * @param mixed $value The value to validate.
     * @return bool True if valid, false otherwise.
     */
    public static function isValidValue(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::VALID_VALUES, strict: true);
    }
}
