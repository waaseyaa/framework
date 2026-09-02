<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Plugin\PluginInspectionInterface;

/**
 * @api
 */
interface FieldTypeInterface extends PluginInspectionInterface
{
    /** @return array<string, array<string, mixed>> */
    public static function schema(): array;

    /** @return array<string, mixed> */
    public static function defaultSettings(): array;

    public static function defaultValue(): mixed;

    public static function jsonSchema(): array;

    /**
     * Per-definition JSON Schema fragment.
     *
     * Allows field-type plugins to vary their JSON Schema by field definition
     * (e.g. enum types reading `settings.enum_class`). Default implementation
     * on AbstractFieldType delegates to the plugin's canonical jsonSchema().
     */
    public static function jsonSchemaFor(FieldDefinitionInterface $def): array;

    /** Canonical schema for the value exposed on an entity authoring surface. */
    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array;

    /** Whether governed application blueprints may declare this type directly. */
    public static function supportsBlueprint(): bool;

    /**
     * Per-definition storage column shape.
     *
     * Allows field-type plugins to vary their storage schema by field
     * definition. Default implementation on AbstractFieldType delegates to the
     * static schema() method so existing field types are unaffected.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemaFor(FieldDefinitionInterface $def): array;

    /**
     * Canonical single-column schema used when an entity backend stores the
     * exposed field value directly under the field name.
     *
     * @return array<string, mixed>
     */
    public static function entityStorageColumnSchemaFor(FieldDefinitionInterface $def): array;
}
