<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Plugin\PluginBase;

/**
 * @api
 *
 * The base class for field-type plugins. It hosts the static "field-type
 * descriptor" seam — `schema()`/`jsonSchema()`/`defaultSettings()`/`defaultValue()`
 * and their per-definition variants — that `FieldTypeManager` resolves by calling
 * `$class::method()` (it never instantiates a field-type plugin). A concrete field
 * type is a `#[FieldType]`-annotated `final class` extending this and implementing
 * `schema()` + `jsonSchema()`.
 *
 * Introduced by audit C-24 to replace `FieldItemBase`, whose dead Field-API
 * item/value-object layer (the `ComplexData`/`TypedData` instance methods, the
 * `validate()` no-op, `PropertyValue`) was instantiated nowhere in production and
 * has been removed (train 2). Custom field types must `extends AbstractFieldType`
 * instead of the removed `FieldItemBase`.
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
     * The plugin's jsonSchema() method is the single structural mapping for the
     * field type. Types that need definition-specific variation (for example,
     * EnumItem reading settings.enum_class) override this method.
     */
    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return static::jsonSchema();
    }

    public static function supportsBlueprint(): bool
    {
        return true;
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return static::jsonSchemaFor($def);
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
