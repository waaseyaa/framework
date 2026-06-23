<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Plugin\PluginInspectionInterface;

/**
 * @api
 */
interface FieldTypeInterface extends PluginInspectionInterface
{
    /** @return array<string, array{type: string, description?: string}> */
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
     * on AbstractFieldType preserves the framework's pre-existing per-type schema
     * mapping so existing field types are unaffected.
     */
    public static function jsonSchemaFor(FieldDefinitionInterface $def): array;

    /**
     * Per-definition storage column shape.
     *
     * Allows field-type plugins to vary their storage schema by field
     * definition. Default implementation on AbstractFieldType delegates to the
     * static schema() method so existing field types are unaffected.
     *
     * @return array<string, array{type: string, description?: string}>
     */
    public static function schemaFor(FieldDefinitionInterface $def): array;
}
