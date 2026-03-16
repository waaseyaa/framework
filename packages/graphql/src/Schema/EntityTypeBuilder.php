<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;

/**
 * Builds GraphQL ObjectType and InputObjectType from EntityType definitions.
 *
 * Uses lazy field thunks (fn() => [...]) so circular entity references
 * (node → taxonomy → node) resolve correctly via TypeRegistry.
 */
final class EntityTypeBuilder
{
    public function __construct(
        private readonly TypeRegistry $registry,
        private readonly FieldTypeMapper $fieldTypeMapper,
        private readonly ReferenceLoader $referenceLoader,
    ) {}

    public function buildObjectType(EntityTypeInterface $entityType): ObjectType
    {
        $typeName = self::toPascalCase($entityType->id());

        /** @var ObjectType */
        return $this->registry->getOrCreate($typeName, fn(): ObjectType => new ObjectType([
            'name' => $typeName,
            'description' => $entityType->getLabel(),
            'fields' => fn(): array => $this->buildOutputFields($entityType),
        ]));
    }

    public function buildInputType(EntityTypeInterface $entityType): InputObjectType
    {
        $typeName = self::toPascalCase($entityType->id()) . 'Input';

        /** @var InputObjectType */
        return $this->registry->getOrCreate($typeName, fn(): InputObjectType => new InputObjectType([
            'name' => $typeName,
            'fields' => fn(): array => $this->buildInputFields($entityType),
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
    private function buildOutputFields(EntityTypeInterface $entityType): array
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

        $fieldDefs = $entityType->getFieldDefinitions();
        $keyValues = array_values($keys);

        foreach ($fieldDefs as $fieldName => $def) {
            // Skip entity keys already handled above
            if (in_array($fieldName, $keyValues, true)) {
                continue;
            }

            $fieldType = $def['type'] ?? 'string';
            $isMultiple = ($def['cardinality'] ?? 1) !== 1;
            $targetEntityTypeId = $def['target_entity_type_id']
                ?? $def['targetEntityTypeId']
                ?? '';

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
                error_log("GraphQL: target type '{$targetTypeName}' not found in registry, falling back to String");
            }

            return $type ?? Type::string();
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

                $depth = ($data['_graphql_depth'] ?? 0) + 1;

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
    private function buildInputFields(EntityTypeInterface $entityType, bool $forCreate = true): array
    {
        $fields = [];
        $keys = $entityType->getKeys();
        $keyValues = array_values($keys);
        $fieldDefs = $entityType->getFieldDefinitions();

        foreach ($fieldDefs as $fieldName => $def) {
            // Skip entity keys (id, uuid) — not user-editable
            if (in_array($fieldName, $keyValues, true)) {
                continue;
            }

            // Skip readOnly fields
            if (!empty($def['readOnly'])) {
                continue;
            }

            $fieldType = $def['type'] ?? 'string';
            $isMultiple = ($def['cardinality'] ?? 1) !== 1;
            $isRequired = !empty($def['required']);

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
