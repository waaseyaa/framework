<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;

/**
 * Builds a complete GraphQL Schema from EntityTypeManager definitions.
 *
 * For each entity type, generates:
 * - Query: {type}(id: ID!), {type}List(filter, sort, offset, limit)
 * - Mutation: create{Type}(input), update{Type}(id, input), delete{Type}(id)
 *
 * Filter/sort/pagination reuses the same QueryApplier as JSON:API.
 */
final class SchemaFactory
{
    private ?InputObjectType $filterInputType = null;
    private ?ObjectType $deleteResultType = null;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityResolver $entityResolver,
        private readonly ReferenceLoader $referenceLoader,
    ) {}

    public function build(): Schema
    {
        $registry = new TypeRegistry();
        $fieldTypeMapper = new FieldTypeMapper();
        $entityTypeBuilder = new EntityTypeBuilder(
            registry: $registry,
            fieldTypeMapper: $fieldTypeMapper,
            referenceLoader: $this->referenceLoader,
        );

        $definitions = $this->entityTypeManager->getDefinitions();

        // Pre-register all ObjectTypes so entity_reference field type callables
        // can resolve targets even for types not yet processed in the loop below.
        foreach ($definitions as $entityType) {
            $entityTypeBuilder->buildObjectType($entityType);
        }

        // Build query and mutation fields
        $queryFields = [];
        $mutationFields = [];

        foreach ($definitions as $entityType) {
            $typeId = $entityType->id();
            $camelCase = EntityTypeBuilder::toCamelCase($typeId);
            $pascalCase = EntityTypeBuilder::toPascalCase($typeId);

            $objectType = $entityTypeBuilder->buildObjectType($entityType);
            $listResultType = $entityTypeBuilder->buildListResultType($entityType);
            $inputType = $entityTypeBuilder->buildInputType($entityType);

            // Query: single entity by ID
            $queryFields[$camelCase] = [
                'type' => $objectType,
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve' => fn(mixed $root, array $args): ?array => $this->entityResolver->resolveSingle($typeId, $args['id']),
            ];

            // Query: entity list with filter/sort/pagination
            $queryFields[$camelCase . 'List'] = [
                'type' => $listResultType,
                'args' => [
                    'filter' => Type::listOf(Type::nonNull($this->getFilterInputType())),
                    'sort' => Type::string(),
                    'offset' => Type::int(),
                    'limit' => Type::int(),
                ],
                'resolve' => fn(mixed $root, array $args): array => $this->entityResolver->resolveList($typeId, $args),
            ];

            // Mutation: create
            $mutationFields['create' . $pascalCase] = [
                'type' => $objectType,
                'args' => [
                    'input' => Type::nonNull($inputType),
                ],
                'resolve' => fn(mixed $root, array $args): array => $this->entityResolver->resolveCreate($typeId, $args['input']),
            ];

            // Mutation: update
            $mutationFields['update' . $pascalCase] = [
                'type' => $objectType,
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                    'input' => Type::nonNull($inputType),
                ],
                'resolve' => fn(mixed $root, array $args): array => $this->entityResolver->resolveUpdate($typeId, $args['id'], $args['input']),
            ];

            // Mutation: delete
            $mutationFields['delete' . $pascalCase] = [
                'type' => $this->getDeleteResultType(),
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve' => fn(mixed $root, array $args): array => [
                    'deleted' => $this->entityResolver->resolveDelete($typeId, $args['id']),
                ],
            ];
        }

        $config = SchemaConfig::create()
            ->setQuery(new ObjectType([
                'name' => 'Query',
                'fields' => $queryFields,
            ]));

        if ($mutationFields !== []) {
            $config->setMutation(new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields,
            ]));
        }

        return new Schema($config);
    }

    private function getFilterInputType(): InputObjectType
    {
        return $this->filterInputType ??= new InputObjectType([
            'name' => 'FilterInput',
            'fields' => [
                'field' => Type::nonNull(Type::string()),
                'value' => Type::nonNull(Type::string()),
                'operator' => [
                    'type' => Type::string(),
                    'defaultValue' => '=',
                ],
            ],
        ]);
    }

    private function getDeleteResultType(): ObjectType
    {
        return $this->deleteResultType ??= new ObjectType([
            'name' => 'DeleteResult',
            'fields' => [
                'deleted' => Type::nonNull(Type::boolean()),
            ],
        ]);
    }
}
