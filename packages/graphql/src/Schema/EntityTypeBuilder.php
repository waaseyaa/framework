<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;

/**
 * Builds GraphQL ObjectType and InputObjectType from EntityType definitions.
 *
 * Uses lazy field thunks (fn() => [...]) so circular entity references
 * (node → taxonomy → node) resolve correctly via TypeRegistry.
 */
final class EntityTypeBuilder
{
    /**
     * Field names that are NEVER emitted in the output schema, regardless of
     * whether the entity declares them with `settings['internal' => true]`.
     * Mirrors ResourceSerializer::ALWAYS_INTERNAL_FIELDS — defense in depth for
     * entities that store credential material in raw `_data` keys.
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];
    public function __construct(
        private readonly TypeRegistry $registry,
        private readonly FieldTypeMapper $fieldTypeMapper,
        private readonly ReferenceLoader $referenceLoader,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * Build the GraphQL ObjectType for an entity type, optionally scoped to a
     * bundle. With a bundle, the type is named `{Type}{Bundle}` (e.g. NodePage)
     * and carries that content type's distinct typed fields, so page and news
     * are genuinely different GraphQL types, not one merged shape.
     */
    public function buildObjectType(EntityTypeInterface $entityType, ?string $bundle = null): ObjectType
    {
        $typeName = self::toPascalCase($entityType->id())
            . ($bundle !== null && $bundle !== '' ? self::toPascalCase($bundle) : '');

        /** @var ObjectType */
        return $this->registry->getOrCreate($typeName, fn(): ObjectType => new ObjectType([
            'name' => $typeName,
            'description' => $bundle !== null && $bundle !== ''
                ? $entityType->getLabel() . ' (' . $bundle . ')'
                : $entityType->getLabel(),
            'fields' => fn(): array => $this->buildOutputFields($entityType, $bundle),
        ]));
    }

    public function buildCreateInputType(EntityTypeInterface $entityType): InputObjectType
    {
        $typeName = self::toPascalCase($entityType->id()) . 'CreateInput';

        /** @var InputObjectType */
        return $this->registry->getOrCreate($typeName, fn(): InputObjectType => new InputObjectType([
            'name' => $typeName,
            'fields' => fn(): array => $this->buildInputFields($entityType, forCreate: true),
        ]));
    }

    public function buildUpdateInputType(EntityTypeInterface $entityType): InputObjectType
    {
        $typeName = self::toPascalCase($entityType->id()) . 'UpdateInput';

        /** @var InputObjectType */
        return $this->registry->getOrCreate($typeName, fn(): InputObjectType => new InputObjectType([
            'name' => $typeName,
            'fields' => fn(): array => $this->buildInputFields($entityType, forCreate: false),
        ]));
    }

    public function buildListResultType(EntityTypeInterface $entityType): ObjectType
    {
        $typeName = self::toPascalCase($entityType->id()) . 'ListResult';
        $objectType = $this->buildObjectType($entityType);

        /** @var ObjectType */
        return $this->registry->getOrCreate($typeName, fn(): ObjectType => new ObjectType([
            'name' => $typeName,
            'fields' => [
                'items' => Type::nonNull(Type::listOf($objectType)),
                'total' => Type::nonNull(Type::int()),
            ],
        ]));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildOutputFields(EntityTypeInterface $entityType, ?string $bundle = null): array
    {
        $fields = [];
        $keys = $entityType->getKeys();

        // Entity key: id (always present, non-null)
        $idField = $keys['id'] ?? 'id';
        $fields['id'] = [
            'type' => Type::nonNull(Type::id()),
            'resolve' => static fn(array $data): string => (string) ($data[$idField] ?? ''),
        ];

        // Entity key: uuid (if defined)
        if (isset($keys['uuid'])) {
            $uuidField = $keys['uuid'];
            $fields['uuid'] = [
                'type' => Type::string(),
                'resolve' => static fn(array $data): ?string => $data[$uuidField] ?? null,
            ];
        }

        // Canonical, bundle-aware field set so a bundle's distinct typed fields
        // surface (e.g. page's body/blocks/featured_image).
        $fieldDefs = $this->entityTypeManager->resolveFieldDefinitions($entityType->id(), $bundle);
        // Only skip id and uuid keys (explicitly re-added above).
        // The label key should pass through as a normal field.
        $skipFields = array_filter([
            $keys['id'] ?? null,
            $keys['uuid'] ?? null,
        ], static fn(?string $v): bool => $v !== null);

        foreach ($fieldDefs as $fieldName => $def) {
            // Skip entity keys already handled above (id, uuid)
            if (in_array($fieldName, $skipFields, true)) {
                continue;
            }

            // Schema-level drop: credential-named fields and fields marked
            // `settings['internal' => true]` must never appear in the output
            // schema — they are neither queryable nor disclosed by introspection.
            // Mirrors the predicate in ResourceSerializer::filterInternalFields().
            if (in_array($fieldName, self::ALWAYS_INTERNAL_FIELDS, true)
                || $def->getSetting('internal') === true) {
                continue;
            }

            $fieldType = $def->getType();
            $isMultiple = $def->isMultiple();
            $targetEntityTypeId = (string) ($def->getSetting('target_entity_type_id')
                ?? $def->getSetting('targetEntityTypeId')
                ?? '');

            if ($this->fieldTypeMapper->isEntityReference($fieldType) && $targetEntityTypeId !== '') {
                $fields[$fieldName] = $this->buildEntityReferenceOutputField(
                    $fieldName,
                    $targetEntityTypeId,
                    $isMultiple,
                );
            } else {
                $graphqlType = $this->fieldTypeMapper->toOutputType($fieldType, $isMultiple);
                $fields[$fieldName] = [
                    'type' => $graphqlType,
                    'resolve' => static fn(array $data) => $data[$fieldName] ?? null,
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntityReferenceOutputField(
        string $fieldName,
        string $targetEntityTypeId,
        bool $isMultiple,
    ): array {
        $registry = $this->registry;
        $referenceLoader = $this->referenceLoader;
        $targetTypeName = self::toPascalCase($targetEntityTypeId);

        // Lazy type resolution — by the time webonyx evaluates this,
        // all entity ObjectTypes will be registered in TypeRegistry.
        $getType = static function () use ($registry, $targetTypeName): Type {
            $type = $registry->get($targetTypeName);
            if ($type === null) {
                throw new \RuntimeException(
                    "GraphQL schema error: entity reference field targets type '{$targetTypeName}' "
                    . 'which is not registered.',
                );
            }

            return $type;
        };

        return [
            'type' => $isMultiple
                ? static fn(): Type => Type::listOf($getType())
                : $getType,
            'resolve' => static function (array $data) use ($fieldName, $targetEntityTypeId, $referenceLoader, $isMultiple): mixed {
                $value = $data[$fieldName] ?? null;
                if ($value === null) {
                    return null;
                }

                $depth = (int) (($data['_graphql_depth'] ?? 0) + 1);

                if ($isMultiple) {
                    $refs = is_array($value) ? $value : [$value];

                    return array_filter(array_map(static function (mixed $ref) use ($targetEntityTypeId, $referenceLoader, $depth): mixed {
                        $targetId = is_array($ref) ? ($ref['target_id'] ?? null) : $ref;

                        return $targetId !== null
                            ? $referenceLoader->load($targetEntityTypeId, $targetId, $depth)
                            : null;
                    }, $refs), static fn(mixed $v): bool => $v !== null);
                }

                $targetId = is_array($value) ? ($value['target_id'] ?? null) : $value;
                if ($targetId === null) {
                    return null;
                }

                return $referenceLoader->load($targetEntityTypeId, $targetId, $depth);
            },
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildInputFields(EntityTypeInterface $entityType, bool $forCreate = true, ?string $bundle = null): array
    {
        $fields = [];
        $keys = $entityType->getKeys();
        // Only skip id and uuid keys (system-managed).
        // The label key should be editable via input types.
        $skipFields = array_filter([
            $keys['id'] ?? null,
            $keys['uuid'] ?? null,
        ], static fn(?string $v): bool => $v !== null);
        $fieldDefs = $this->entityTypeManager->resolveFieldDefinitions($entityType->id(), $bundle);

        foreach ($fieldDefs as $fieldName => $def) {
            // Skip entity keys (id, uuid) — not user-editable
            if (in_array($fieldName, $skipFields, true)) {
                continue;
            }

            // Skip readOnly fields
            if ($def->isReadOnly()) {
                continue;
            }

            $fieldType = $def->getType();
            $isMultiple = $def->isMultiple();
            $isRequired = $def->isRequired();

            $graphqlType = $this->fieldTypeMapper->toInputType($fieldType, $isMultiple);
            if ($isRequired && $forCreate) {
                $graphqlType = Type::nonNull($graphqlType);
            }

            $fields[$fieldName] = [
                'type' => $graphqlType,
            ];
        }

        return $fields;
    }

    public static function toPascalCase(string $name): string
    {
        return str_replace('_', '', ucwords($name, '_'));
    }

    public static function toCamelCase(string $name): string
    {
        return lcfirst(self::toPascalCase($name));
    }
}
