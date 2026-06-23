<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Plugin\PluginBase;

/**
 * Non-instance base for field-type plugins: hosts the live, static "field-type
 * descriptor" seam — `schema()`/`jsonSchema()`/`defaultSettings()`/`defaultValue()`
 * and their per-definition variants — that `FieldTypeManager` resolves by calling
 * `$class::method()` (it never instantiates a field-type plugin).
 *
 * Extracted off {@see FieldItemBase} (audit C-24, train 1) so the live static seam
 * no longer lives on the dead Field-API item/value-object layer (the ComplexData /
 * TypedData instance methods, `validate()` no-op, `PropertyValue`). `FieldItemBase`
 * now `extends` this, so the seam is single-sourced and behaviour is unchanged;
 * train 2 reparents the field-type plugins directly onto this base and removes the
 * dead instance layer (and the tests that exercise it) together.
 *
 * @internal Transitional base; promotes to the field-type plugin parent in C-24
 *   train 2. Concrete plugins provide `schema()`/`jsonSchema()`.
 */
abstract class AbstractFieldType extends PluginBase implements FieldTypeInterface
{
    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return [];
    }

    public static function defaultValue(): mixed
    {
        return null;
    }

    /**
     * Default per-definition JSON Schema.
     *
     * Reproduces the legacy per-type mapping that previously lived as a hardcoded
     * match in `FieldDefinition::toJsonSchema()`. Field types that need
     * per-definition variation (e.g. `EnumItem` reading `settings.enum_class`)
     * override this method; types happy with the legacy mapping (string, integer,
     * boolean, float, text, entity_reference) get bit-identical behaviour with zero
     * overrides. Intentionally returns the legacy mapping rather than delegating to
     * `static::jsonSchema()` — the latter is the richer per-type schema, but
     * `FieldDefinition::toJsonSchema()` has historically emitted this minimal shape,
     * and preserving that emission contract is mandated by the WP01 regression test.
     */
    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return match ($def->getType()) {
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'float' => ['type' => 'number'],
            'text' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ],
            'entity_reference' => [
                'type' => 'object',
                'properties' => [
                    'target_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                ],
            ],
            default => ['type' => 'string'],
        };
    }

    /**
     * Default per-definition storage schema. Delegates to the static `schema()`
     * method, preserving behaviour for every existing field type.
     *
     * @return array<string, array{type: string, description?: string}>
     */
    public static function schemaFor(FieldDefinitionInterface $def): array
    {
        return static::schema();
    }
}
