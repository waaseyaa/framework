<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

use Waaseyaa\Entity\Exception\EntityMetadataException;

/**
 * Pure helper that maps a PHP property declaration plus its `#[Field]` attribute
 * to a concrete `{type, required, settings}` triple ready for FieldDefinition
 * construction.
 *
 * The inferrer is stateless and side-effect free — same input always yields the
 * same output. It does not touch the container, the database, or the
 * EntityTypeManager. All errors are reported via {@see EntityMetadataException}
 * and include the offending class FQN, property name, and a remediation hint
 * (NFR-004).
 * @api
 */
final class FieldTypeInferrer
{
    /**
     * The set of registered field-type ids accepted by `waaseyaa/field`.
     *
     * @var list<string>
     */
    public const VALID_TYPE_IDS = [
        'boolean',
        'classification_label',
        'computed',
        'date',
        'datetime',
        'decimal',
        'email',
        'entity_reference',
        'enum',
        'file',
        'float',
        'image',
        'integer',
        'json',
        'link',
        'list',
        'string',
        'text',
    ];

    /**
     * PHP scalar / built-in class → field-type id mapping.
     *
     * @var array<string, string>
     */
    private const SCALAR_MAP = [
        'string' => 'string',
        'int' => 'integer',
        'bool' => 'boolean',
        'float' => 'float',
        'array' => 'json',
        \DateTimeImmutable::class => 'datetime',
    ];

    /**
     * Resolve the effective field-type id, required flag, and merged settings for
     * a `#[Field]`-decorated property.
     *
     * @return array{type: string, required: bool, settings: array<string, mixed>}
     *
     * @throws EntityMetadataException When the PHP type is not inferable and no explicit
     *                                 `type:` is given, when the explicit `type:` is unknown,
     *                                 or when the explicit `type:` conflicts with the PHP type.
     */
    public static function infer(\ReflectionProperty $property, Field $attribute): array
    {
        $reflectionType = $property->getType();
        $isNullable = $reflectionType?->allowsNull() ?? false;
        $phpTypeName = self::resolvePhpTypeName($reflectionType);

        // Inferred settings (currently only enum_class for backed enums).
        $inferredSettings = [];
        $inferredType = null;
        if ($phpTypeName !== null) {
            $inferredType = self::mapPhpTypeToFieldType($phpTypeName, $inferredSettings);
        }

        if ($attribute->type !== null) {
            self::assertValidTypeId($attribute->type, $property);

            // AS-8 / C-004 hard cutover: explicit type='string' on a backed-enum
            // property is no longer supported. The legacy bridge ('string' +
            // settings.enum_class) was retired when EnumItem became the
            // canonical home for enum schema. Raise a clear diagnostic instead
            // of silently coercing the type or swallowing the user's intent.
            if ($inferredType === 'enum' && $attribute->type === 'string') {
                throw self::backedEnumExplicitStringRejection($property);
            }

            // If both inferred and explicit are present, require compatibility.
            if ($inferredType !== null && !self::isCompatible($inferredType, $attribute->type)) {
                throw self::conflictException($property, $phpTypeName ?? '(unknown)', $inferredType, $attribute->type);
            }

            $required = $attribute->required ?? !$isNullable;
            $settings = array_merge($inferredSettings, $attribute->settings);

            return [
                'type' => $attribute->type,
                'required' => $required,
                'settings' => $settings,
            ];
        }

        // No explicit `type:` — must be inferable.
        if ($inferredType === null) {
            throw self::cannotInferException($property, $reflectionType, $phpTypeName);
        }

        $required = $attribute->required ?? !$isNullable;
        $settings = array_merge($inferredSettings, $attribute->settings);

        return [
            'type' => $inferredType,
            'required' => $required,
            'settings' => $settings,
        ];
    }

    /**
     * Extract a single named PHP type from a reflection type, or null if the type
     * is absent, a union, or an intersection (those force the user to pass `type:`).
     */
    private static function resolvePhpTypeName(?\ReflectionType $reflectionType): ?string
    {
        if ($reflectionType instanceof \ReflectionNamedType) {
            return $reflectionType->getName();
        }

        // Union, intersection, or untyped — not inferable.
        return null;
    }

    /**
     * Map a PHP type name to a field-type id. Populates `$settings` for cases like
     * backed enums where additional configuration must be injected.
     *
     * Returns null when the PHP type is not in the supported inference table.
     *
     * @param array<string, mixed> $settings
     */
    private static function mapPhpTypeToFieldType(string $phpTypeName, array &$settings): ?string
    {
        if (isset(self::SCALAR_MAP[$phpTypeName])) {
            return self::SCALAR_MAP[$phpTypeName];
        }

        if (\class_exists($phpTypeName) && \is_subclass_of($phpTypeName, \BackedEnum::class)) {
            $settings['enum_class'] = $phpTypeName;

            // The inferrer no longer chooses a backing-type-specific id (string
            // vs integer). Column shape is owned by EnumItem::schemaFor(), which
            // inspects the cases on demand.
            return 'enum';
        }

        return null;
    }

    /**
     * Compatibility groups: an inferred field type id can be overridden with any
     * other id in the same group. Groups are kept narrow on purpose — text-like
     * ids share a string PHP representation; numeric ids share float storage; etc.
     *
     * Field-type ids not appearing in any group are only compatible with themselves.
     *
     * @var list<list<string>>
     */
    private const COMPATIBILITY_GROUPS = [
        ['string', 'text', 'email', 'link', 'classification_label'],
        ['integer', 'list'],
        ['float', 'decimal'],
        ['datetime', 'date'],
    ];

    /**
     * Asymmetric override rule: inferred field-type ids that may be explicitly
     * overridden to `entity_reference`. The reverse direction is never
     * permitted — `entity_reference` is not inferred from any PHP scalar, since
     * a bare `int` or `string` is not, by itself, evidence of an FK intent.
     *
     * Kept separate from {@see self::COMPATIBILITY_GROUPS} (symmetric, exposed
     * via {@see self::compatibilityGroups()} for static analysis) so the public
     * seam's contract — "either side may override the other" — stays honest.
     *
     * @var list<string>
     */
    private const ENTITY_REFERENCE_COMPATIBLE_INFERRED = ['integer', 'string'];

    /**
     * Public seam for static analysis: returns true when an inferred field-type
     * id may be overridden with the explicit field-type id given on the `#[Field]`
     * attribute. Mirrors the runtime check used by {@see self::infer()}.
     *
     * Consumed by `FieldAttributeRule` (PHPStan extension) so the static checker
     * and the runtime inferrer share a single source of truth — including the
     * asymmetric `entity_reference` rule which {@see self::compatibilityGroups()}
     * deliberately does not expose.
     */
    public static function isCompatible(string $inferred, string $explicit): bool
    {
        if ($inferred === $explicit) {
            return true;
        }

        foreach (self::COMPATIBILITY_GROUPS as $group) {
            if (\in_array($inferred, $group, true) && \in_array($explicit, $group, true)) {
                return true;
            }
        }

        if ($explicit === 'entity_reference'
            && \in_array($inferred, self::ENTITY_REFERENCE_COMPATIBLE_INFERRED, true)) {
            return true;
        }

        return false;
    }

    /**
     * Public seam for static analysis: returns the same compatibility-group
     * table {@see self::isCompatible()} consults at runtime.
     *
     * @return list<list<string>>
     */
    public static function compatibilityGroups(): array
    {
        return self::COMPATIBILITY_GROUPS;
    }

    /**
     * Public seam for static analysis: maps a PHP type name to a field-type id
     * without requiring a {@see \ReflectionProperty}. Mirrors the inference
     * branch of {@see self::infer()}.
     *
     * Returns null when `$phpTypeName` is null or not in the inference table.
     * Callers should pass null for union/intersection/missing types.
     *
     * @param array<string, mixed> $settings  Out-parameter for backed-enum metadata.
     *                                        Required (no default) so callers cannot
     *                                        silently drop the enum_class side effect.
     */
    public static function inferFromPhpTypeName(?string $phpTypeName, array &$settings): ?string
    {
        if ($phpTypeName === null) {
            return null;
        }

        return self::mapPhpTypeToFieldType($phpTypeName, $settings);
    }

    private static function assertValidTypeId(string $typeId, \ReflectionProperty $property): void
    {
        if (\in_array($typeId, self::VALID_TYPE_IDS, true)) {
            return;
        }

        $location = self::propertyLocation($property);
        $valid = \implode(', ', self::VALID_TYPE_IDS);

        throw new EntityMetadataException(\sprintf(
            'Unknown field type id "%s" on %s. Valid ids: %s. Hint: pass one of the registered field-type ids to #[Field(type: ...)] or omit it to use inference.',
            $typeId,
            $location,
            $valid,
        ));
    }

    private static function backedEnumExplicitStringRejection(\ReflectionProperty $property): EntityMetadataException
    {
        $location = self::propertyLocation($property);

        return new EntityMetadataException(\sprintf(
            "Field %s: explicit type='string' on backed-enum property %s is no longer supported. Remove the explicit type or use type='enum' (enum_class is inferred automatically).",
            $location,
            $property->getName(),
        ));
    }

    private static function conflictException(
        \ReflectionProperty $property,
        string $phpTypeName,
        string $inferredFieldType,
        string $explicitFieldType,
    ): EntityMetadataException {
        $location = self::propertyLocation($property);

        return new EntityMetadataException(\sprintf(
            'Conflicting field type for %s: PHP type "%s" infers field type "%s" but #[Field(type: "%s")] was given. Hint: remove the explicit type:, change the property type, or pick a compatible field-type id.',
            $location,
            $phpTypeName,
            $inferredFieldType,
            $explicitFieldType,
        ));
    }

    private static function cannotInferException(
        \ReflectionProperty $property,
        ?\ReflectionType $reflectionType,
        ?string $phpTypeName,
    ): EntityMetadataException {
        $location = self::propertyLocation($property);
        $reason = self::describeUninferableType($reflectionType, $phpTypeName);

        return new EntityMetadataException(\sprintf(
            'Cannot infer field type for %s (%s). Hint: declare a supported property type (string, int, bool, float, array, \DateTimeImmutable, or a backed enum) or pass type: explicitly to #[Field].',
            $location,
            $reason,
        ));
    }

    private static function describeUninferableType(?\ReflectionType $reflectionType, ?string $phpTypeName): string
    {
        if ($reflectionType === null) {
            return 'property has no type declaration';
        }

        if ($reflectionType instanceof \ReflectionUnionType) {
            return 'union types are not supported';
        }

        if ($reflectionType instanceof \ReflectionIntersectionType) {
            return 'intersection types are not supported';
        }

        if ($phpTypeName !== null) {
            return \sprintf('PHP type "%s" is not in the inference table', $phpTypeName);
        }

        return 'unsupported PHP type';
    }

    private static function propertyLocation(\ReflectionProperty $property): string
    {
        return \sprintf('%s::$%s', $property->getDeclaringClass()->getName(), $property->getName());
    }
}
