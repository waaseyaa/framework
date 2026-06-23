<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Field-type plugin for backed PHP enums.
 *
 * Owns four contracts for enum-typed fields:
 *  - storage column shape (varchar(255) for string-backed, int for int-backed),
 *  - JSON Schema fragment for `waaseyaa/ai-schema` (`{type, enum: [...]}`),
 *  - runtime validation/coercion against the declared `BackedEnum`,
 *  - case-label resolution for admin widgets, with `LabeledCase` opt-in.
 *
 * Auto-discovered via `#[FieldType(id: 'enum')]`. The `enum_class` setting on
 * a `FieldDefinition` selects the BackedEnum to bind. Static `schema()` and
 * `jsonSchema()` deliberately throw to make per-type misuse loud — callers
 * must use the per-definition `schemaFor()` / `jsonSchemaFor()` seams added
 * by WP01.
 * @api
 */
#[FieldType(id: 'enum', label: 'Enum')]
final class EnumItem extends AbstractFieldType
{
    /**
     * Memoization cache for `\ReflectionEnum`, keyed by enum FQCN.
     *
     * NFR-001: reflection cost on every hydrate must amortize to O(1) per
     * enum class, regardless of how many entity instances are loaded.
     *
     * @var array<class-string, \ReflectionEnum>
     */
    private static array $reflectionCache = [];

    public static function defaultSettings(): array
    {
        return ['enum_class' => null];
    }

    /**
     * Static schema is intentionally unsupported. EnumItem requires a
     * `FieldDefinition` to know which BackedEnum to bind; calling the
     * type-level path silently would degrade to the wrong column shape.
     *
     * @throws \LogicException Always.
     */
    public static function schema(): array
    {
        throw new \LogicException(
            'EnumItem requires per-definition resolution; use schemaFor(FieldDefinitionInterface) instead.',
        );
    }

    /**
     * Static JSON schema is intentionally unsupported. Same reasoning as
     * `schema()` — without a definition we cannot enumerate cases.
     *
     * @throws \LogicException Always.
     */
    public static function jsonSchema(): array
    {
        throw new \LogicException(
            'EnumItem requires per-definition resolution; use jsonSchemaFor(FieldDefinitionInterface) instead.',
        );
    }

    public static function schemaFor(FieldDefinitionInterface $def): array
    {
        $reflectionEnum = self::reflectionFor($def);
        $backing = $reflectionEnum->getBackingType()?->getName();

        return $backing === 'string'
            ? ['value' => ['type' => 'varchar', 'length' => 255]]
            : ['value' => ['type' => 'int']];
    }

    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        $reflectionEnum = self::reflectionFor($def);
        $backing = $reflectionEnum->getBackingType()?->getName();
        /** @var class-string<\BackedEnum> $enumClass */
        $enumClass = $reflectionEnum->getName();

        $cases = array_map(
            static fn(\BackedEnum $case): int|string => $case->value,
            $enumClass::cases(),
        );

        return [
            'type' => $backing === 'string' ? 'string' : 'integer',
            'enum' => $cases,
        ];
    }

    /**
     * Coerce a runtime input (scalar or BackedEnum instance) into a case of
     * the BackedEnum bound to `$def`.
     *
     * Accepts:
     *  - a `\BackedEnum` instance whose class === `settings.enum_class`
     *    (returned as-is),
     *  - a scalar matching one of the declared case backing values.
     *
     * Anything else raises `EnumFieldType.InvalidInputValue`.
     */
    public function castToCase(mixed $value, FieldDefinitionInterface $def): \BackedEnum
    {
        $reflectionEnum = self::reflectionFor($def);
        /** @var class-string<\BackedEnum> $enumClass */
        $enumClass = $reflectionEnum->getName();

        if ($value instanceof \BackedEnum) {
            if (!($value instanceof $enumClass)) {
                throw new EnumFieldTypeException(
                    EnumFieldTypeException::INVALID_INPUT_VALUE,
                    sprintf(
                        'Field "%s" expects BackedEnum of class %s, got instance of %s.',
                        $def->getName(),
                        $enumClass,
                        $value::class,
                    ),
                    fieldName: $def->getName(),
                    enumClass: $enumClass,
                );
            }

            return $value;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::INVALID_INPUT_VALUE,
                sprintf(
                    'Field "%s" (enum %s) requires a scalar or %s instance; got %s.',
                    $def->getName(),
                    $enumClass,
                    $enumClass,
                    get_debug_type($value),
                ),
                fieldName: $def->getName(),
                enumClass: $enumClass,
            );
        }

        $case = $enumClass::tryFrom($value);
        if ($case === null) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::INVALID_INPUT_VALUE,
                sprintf(
                    'Field "%s" received value %s which is not a valid case of %s.',
                    $def->getName(),
                    var_export($value, true),
                    $enumClass,
                ),
                fieldName: $def->getName(),
                enumClass: $enumClass,
            );
        }

        return $case;
    }

    /**
     * Hydrate a stored scalar to a BackedEnum case.
     *
     * Covers EC-2: an enum case may have been removed after data was written.
     * Raises `EnumFieldType.InvalidStoredValue` rather than silently losing
     * the row.
     */
    public function hydrate(mixed $stored, FieldDefinitionInterface $def): \BackedEnum
    {
        $reflectionEnum = self::reflectionFor($def);
        /** @var class-string<\BackedEnum> $enumClass */
        $enumClass = $reflectionEnum->getName();

        if (!is_int($stored) && !is_string($stored)) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::INVALID_STORED_VALUE,
                sprintf(
                    'Field "%s" (enum %s) read a non-scalar stored value of type %s.',
                    $def->getName(),
                    $enumClass,
                    get_debug_type($stored),
                ),
                fieldName: $def->getName(),
                enumClass: $enumClass,
            );
        }

        $case = $enumClass::tryFrom($stored);
        if ($case === null) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::INVALID_STORED_VALUE,
                sprintf(
                    'Field "%s" stored value %s is not a valid case of %s (enum may have been edited after data was written).',
                    $def->getName(),
                    var_export($stored, true),
                    $enumClass,
                ),
                fieldName: $def->getName(),
                enumClass: $enumClass,
            );
        }

        return $case;
    }

    /**
     * Return `[<backing_value> => <label>]` ordered by case declaration.
     *
     * Labels resolve via `LabeledCase::getLabel()` if the enum implements
     * that interface; otherwise fall back to `$case->name`.
     *
     * @param class-string $enumClass
     * @return array<int|string, string>
     */
    public static function casesForEnumClass(string $enumClass): array
    {
        $reflectionEnum = self::assertValidEnumClass($enumClass, '<enum cases helper>');
        /** @var class-string<\BackedEnum> $fqcn */
        $fqcn = $reflectionEnum->getName();

        $result = [];
        foreach ($fqcn::cases() as $case) {
            $label = $case instanceof LabeledCase ? $case->getLabel() : $case->name;
            $result[$case->value] = $label;
        }

        return $result;
    }

    /**
     * Resolve and memoize the `\ReflectionEnum` for the field definition's
     * `enum_class` setting, raising the appropriate variant on misconfiguration.
     */
    private static function reflectionFor(FieldDefinitionInterface $def): \ReflectionEnum
    {
        $enumClass = $def->getSetting('enum_class');
        if ($enumClass !== null && !is_string($enumClass)) {
            // Non-string setting: treat as missing/unknown for diagnostic clarity.
            $enumClass = null;
        }

        return self::assertValidEnumClass($enumClass, $def->getName());
    }

    /**
     * Validate `$enumClass` and return a memoized `\ReflectionEnum`.
     *
     * Raises one of the four configuration variants on failure (NFR-002:
     * messages always carry both the offending field name and the enum FQCN
     * when known).
     */
    private static function assertValidEnumClass(?string $enumClass, string $fieldName): \ReflectionEnum
    {
        if ($enumClass === null || $enumClass === '') {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::MISSING_ENUM_CLASS,
                sprintf(
                    'Field "%s" is missing required setting "enum_class".',
                    $fieldName,
                ),
                fieldName: $fieldName,
                enumClass: null,
            );
        }

        if (isset(self::$reflectionCache[$enumClass])) {
            return self::$reflectionCache[$enumClass];
        }

        if (!class_exists($enumClass) && !enum_exists($enumClass)) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::UNKNOWN_ENUM_CLASS,
                sprintf(
                    'Field "%s" references unknown enum class %s.',
                    $fieldName,
                    $enumClass,
                ),
                fieldName: $fieldName,
                enumClass: $enumClass,
            );
        }

        if (!enum_exists($enumClass)) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::NOT_A_BACKED_ENUM,
                sprintf(
                    'Field "%s" references %s, which is not an enum.',
                    $fieldName,
                    $enumClass,
                ),
                fieldName: $fieldName,
                enumClass: $enumClass,
            );
        }

        $reflectionEnum = new \ReflectionEnum($enumClass);

        if (!$reflectionEnum->isBacked()) {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::NOT_A_BACKED_ENUM,
                sprintf(
                    'Field "%s" references %s, which is a unit (non-backed) enum. EnumItem requires a backed enum.',
                    $fieldName,
                    $enumClass,
                ),
                fieldName: $fieldName,
                enumClass: $enumClass,
            );
        }

        $backingTypeName = $reflectionEnum->getBackingType()?->getName();
        if ($backingTypeName !== 'string' && $backingTypeName !== 'int') {
            throw new EnumFieldTypeException(
                EnumFieldTypeException::UNSUPPORTED_BACKING_TYPE,
                sprintf(
                    'Field "%s" enum %s has unsupported backing type %s; only string and int are supported.',
                    $fieldName,
                    $enumClass,
                    $backingTypeName ?? 'unknown',
                ),
                fieldName: $fieldName,
                enumClass: $enumClass,
            );
        }

        self::$reflectionCache[$enumClass] = $reflectionEnum;

        return $reflectionEnum;
    }
}
