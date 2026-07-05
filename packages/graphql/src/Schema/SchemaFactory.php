<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\GraphQL\GraphQlExecutionContext;

/**
 * Builds a complete GraphQL Schema from EntityTypeManager definitions.
 *
 * For each entity type, generates:
 * - Query: {type}(id: ID!), {type}List(filter, sort, offset, limit)
 * - Mutation: create{Type}(input), update{Type}(id, input), delete{Type}(id)
 *
 * Applications can override specific mutations via withMutationOverrides()
 * to add custom args or replace the default resolver.
 *
 * Filter/sort/pagination reuses the same QueryApplier as JSON:API.
 *
 * R12 (audit A10, SECURITY): the built Schema is cached across requests (see
 * $schemaCache) and MUST hold no per-request/account-bound state. The
 * query/mutation resolver closures below therefore read the per-request
 * EntityResolver from the GraphQL execution context (the resolver's 3rd
 * argument, a {@see GraphQlExecutionContext} constructed fresh per request
 * by {@see \Waaseyaa\GraphQL\GraphQlEndpoint::handle()}), never from a
 * captured `$this->entityResolver` -- a per-request collaborator captured by
 * closure would otherwise leak the FIRST request's account/data to every
 * later request that hits this cache, under worker-mode process reuse.
 * @api
 */
final class SchemaFactory
{
    private ?InputObjectType $filterInputType = null;
    private ?ObjectType $deleteResultType = null;

    /** @var array<string, array{args?: array<string, mixed>, resolve?: callable}> */
    private array $mutationOverrides = [];

    /** @var array<string, Schema> Static per-process cache keyed by entity type ID hash. */
    private static array $schemaCache = [];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * Register overrides for specific mutations.
     *
     * Each key is a mutation name (e.g. 'updateScheduleEntry').
     * Each value is an array with optional 'args' (merged with defaults)
     * and optional 'resolve' (replaces the default resolver).
     *
     * @param array<string, array{args?: array<string, mixed>, resolve?: callable}> $overrides
     */
    public function withMutationOverrides(array $overrides): self
    {
        $clone = clone $this;
        $clone->mutationOverrides = array_merge($clone->mutationOverrides, $overrides);
        return $clone;
    }

    /**
     * Reset the static schema cache.
     *
     * Useful in testing or when entity type definitions change at runtime.
     */
    public static function resetCache(): void
    {
        self::$schemaCache = [];
    }

    public function build(): Schema
    {
        $definitions = $this->entityTypeManager->getDefinitions();
        $typeIds = array_map(fn($def) => $def->id(), $definitions);
        sort($typeIds);
        $overrideKeys = array_keys($this->mutationOverrides);
        sort($overrideKeys);
        $cacheKey = hash('xxh128', implode(',', $typeIds) . '|' . implode(',', $overrideKeys));

        if (isset(self::$schemaCache[$cacheKey])) {
            return self::$schemaCache[$cacheKey];
        }

        $registry = new TypeRegistry();
        $fieldTypeMapper = new FieldTypeMapper();
        $entityTypeBuilder = new EntityTypeBuilder(
            registry: $registry,
            fieldTypeMapper: $fieldTypeMapper,
            entityTypeManager: $this->entityTypeManager,
        );

        // Pre-register all ObjectTypes so entity_reference field type callables
        // can resolve targets even for types not yet processed in the loop below.
        foreach ($definitions as $entityType) {
            $entityTypeBuilder->buildObjectType($entityType);
        }

        // Build per-bundle object types (e.g. NodePage, NodeNews) so each content
        // type surfaces its distinct typed fields in GraphQL introspection, not a
        // single merged shape. These are additive: the base type and all existing
        // queries/mutations are unchanged. Bundles are sourced from the bundle
        // config entities (the registered content types).
        $bundleTypes = [];
        foreach ($definitions as $entityType) {
            foreach ($this->bundlesFor($entityType) as $bundle) {
                $bundleTypes[] = $entityTypeBuilder->buildObjectType($entityType, $bundle);
            }
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
            $createInputType = $entityTypeBuilder->buildCreateInputType($entityType);
            $updateInputType = $entityTypeBuilder->buildUpdateInputType($entityType);

            // Query: single entity by ID
            $queryFields[$camelCase] = [
                'type' => $objectType,
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve' => static fn(mixed $root, array $args, GraphQlExecutionContext $context): ?array =>
                    $context->entityResolver->resolveSingle($typeId, $args['id']),
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
                'resolve' => static fn(mixed $root, array $args, GraphQlExecutionContext $context): array =>
                    $context->entityResolver->resolveList($typeId, $args),
            ];

            // Mutation: create
            $createName = 'create' . $pascalCase;
            $mutationFields[$createName] = $this->applyOverride($createName, [
                'type' => $objectType,
                'args' => [
                    'input' => Type::nonNull($createInputType),
                ],
                'resolve' => static fn(mixed $root, array $args, GraphQlExecutionContext $context): array =>
                    $context->entityResolver->resolveCreate($typeId, $args['input']),
            ]);

            // Mutation: update
            $updateName = 'update' . $pascalCase;
            $mutationFields[$updateName] = $this->applyOverride($updateName, [
                'type' => $objectType,
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                    'input' => Type::nonNull($updateInputType),
                ],
                'resolve' => static fn(mixed $root, array $args, GraphQlExecutionContext $context): array =>
                    $context->entityResolver->resolveUpdate($typeId, $args['id'], $args['input']),
            ]);

            // Mutation: delete
            $deleteName = 'delete' . $pascalCase;
            $mutationFields[$deleteName] = $this->applyOverride($deleteName, [
                'type' => $this->getDeleteResultType(),
                'args' => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve' => static fn(mixed $root, array $args, GraphQlExecutionContext $context): array => [
                    'deleted' => $context->entityResolver->resolveDelete($typeId, $args['id']),
                ],
            ]);
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

        // Per-bundle types are not referenced by any query/mutation field, so
        // register them explicitly to keep them in the schema (and introspection).
        if ($bundleTypes !== []) {
            $config->setTypes($bundleTypes);
        }

        $schema = new Schema($config);
        self::$schemaCache[$cacheKey] = $schema;

        return $schema;
    }

    /**
     * Bundle ids for an entity type, sourced from its registered bundle config
     * entities (e.g. node_type rows = the registered content types). Returns []
     * when the type has no bundle container or none are registered. Best-effort:
     * storage errors degrade to no per-bundle types rather than failing schema
     * construction.
     *
     * @return list<string>
     */
    private function bundlesFor(EntityTypeInterface $entityType): array
    {
        $bundleTypeId = $entityType->getBundleEntityType();
        if ($bundleTypeId === null || $bundleTypeId === '') {
            return [];
        }
        if (!$this->entityTypeManager->hasDefinition($bundleTypeId)) {
            return [];
        }

        try {
            // C-22 WP3: read path now goes through the canonical repository.
            // findBy([]) is the "load all" equivalent of loadMultiple() with no ids.
            $bundles = [];
            foreach ($this->entityTypeManager->getRepository($bundleTypeId)->findBy([]) as $configEntity) {
                $id = $configEntity->id();
                if (is_string($id) && $id !== '') {
                    $bundles[] = $id;
                }
            }
            sort($bundles);

            return $bundles;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Apply any registered override for a mutation field definition.
     *
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    private function applyOverride(string $mutationName, array $default): array
    {
        if (!isset($this->mutationOverrides[$mutationName])) {
            return $default;
        }

        $override = $this->mutationOverrides[$mutationName];

        if (isset($override['args'])) {
            $default['args'] = array_merge($default['args'], $override['args']);
        }

        if (isset($override['resolve'])) {
            $default['resolve'] = $override['resolve'];
        }

        return $default;
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
