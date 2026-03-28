<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Resolver;

use GraphQL\Error\UserError;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Query\ParsedQuery;
use Waaseyaa\Api\Query\QueryApplier;
use Waaseyaa\Api\Query\QueryFilter;
use Waaseyaa\Api\Query\QuerySort;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;

/**
 * Resolves GraphQL queries and mutations against entity storage.
 *
 * Reuses QueryApplier from the JSON:API layer for consistent
 * filter/sort/pagination semantics across API surfaces.
 */
final class EntityResolver
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly GraphQlAccessGuard $guard,
        private readonly ?AccountInterface $account = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Resolve a list query with filter, sort, and pagination.
     *
     * **_data blob performance note:** Filters and sorts on fields stored in the
     * `_data` JSON blob work via `json_extract()` fallback in SqlEntityQuery, but
     * cannot use column indexes. For high-traffic query fields, promote them to
     * dedicated schema columns in SqlSchemaHandler for indexed performance.
     *
     * @param array<string, mixed> $args GraphQL list query arguments
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function resolveList(string $entityTypeId, array $args): array
    {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);

        $filters = $this->parseFilters($args);
        $sorts = $this->parseSorts($args);
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $limit = isset($args['limit']) ? min(self::MAX_LIMIT, max(1, (int) $args['limit'])) : self::DEFAULT_LIMIT;

        // Count query — filters only, no sorts/pagination, access deferred to post-fetch
        $countQuery = $storage->getQuery()->accessCheck(false);
        foreach ($filters as $filter) {
            $countQuery->condition($filter->field, $filter->value, $filter->operator);
        }
        $countQuery->count();
        $countResult = $countQuery->execute();
        $total = (int) ($countResult[0] ?? 0);

        // Main query via QueryApplier — access deferred to post-fetch
        $parsedQuery = new ParsedQuery(
            filters: $filters,
            sorts: $sorts,
            offset: $offset,
            limit: $limit,
        );
        $applier = new QueryApplier();
        $mainQuery = $applier->apply($parsedQuery, $storage->getQuery()->accessCheck(false));
        $ids = $mainQuery->execute();

        $entities = $ids !== [] ? $storage->loadMultiple($ids) : [];

        // Post-fetch access filtering
        $items = [];
        foreach ($entities as $entity) {
            if (!$this->guard->canView($entity)) {
                continue;
            }
            $values = $entity->toArray();
            $allowed = $this->guard->filterFields($entity, array_keys($values), 'view');
            $data = array_intersect_key($values, array_flip($allowed));
            $data['_graphql_depth'] = 0;
            $items[] = $data;
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveSingle(string $entityTypeId, int|string $id): ?array
    {
        $entity = $this->loadEntity($entityTypeId, $id);
        if ($entity === null) {
            return null;
        }
        if (!$this->guard->canView($entity)) {
            $this->logger->info(sprintf('GraphQL: view access denied for %s/%s', $entityTypeId, (string) $id));

            return null;
        }

        $values = $entity->toArray();
        $allowed = $this->guard->filterFields($entity, array_keys($values), 'view');
        $data = array_intersect_key($values, array_flip($allowed));
        $data['_graphql_depth'] = 0;

        return $data;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolveCreate(string $entityTypeId, array $input): array
    {
        $this->guard->assertCreateAccess($entityTypeId);

        $input = $this->injectAccountContext($entityTypeId, $input);

        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $entity = $storage->create($input);

        foreach (array_keys($input) as $fieldName) {
            $this->guard->assertFieldEditAccess($entity, $fieldName);
        }

        $entity->enforceIsNew();
        $storage->save($entity);

        $values = $entity->toArray();
        $values['_graphql_depth'] = 0;

        return $values;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolveUpdate(string $entityTypeId, int|string $id, array $input): array
    {
        $entity = $this->loadEntity($entityTypeId, $id);
        if ($entity === null) {
            throw new UserError("Entity not found: {$entityTypeId}/{$id}");
        }

        $this->guard->assertUpdateAccess($entity);

        foreach (array_keys($input) as $fieldName) {
            $this->guard->assertFieldEditAccess($entity, $fieldName);
        }

        if (!$entity instanceof FieldableInterface) {
            throw new UserError("Entity type '{$entityTypeId}' does not support field updates.");
        }
        foreach ($input as $field => $value) {
            $entity->set($field, $value);
        }

        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $storage->save($entity);

        $values = $entity->toArray();
        $values['_graphql_depth'] = 0;

        return $values;
    }

    public function resolveDelete(string $entityTypeId, int|string $id): bool
    {
        $entity = $this->loadEntity($entityTypeId, $id);
        if ($entity === null) {
            throw new UserError("Entity not found: {$entityTypeId}/{$id}");
        }

        $this->guard->assertDeleteAccess($entity);

        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $storage->delete([$entity]);

        return true;
    }

    private function loadEntity(string $entityTypeId, int|string $id): ?EntityInterface
    {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        // UUID detection — same pattern as JsonApiController::loadByIdOrUuid
        if (is_string($id) && isset($keys['uuid'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
        ) {
            $ids = $storage->getQuery()->condition($keys['uuid'], $id)->execute();
            if ($ids === []) {
                return null;
            }
            return $storage->load(reset($ids));
        }

        return $storage->load(is_numeric($id) ? (int) $id : $id);
    }

    /**
     * Parse GraphQL filter arguments into QueryFilter objects.
     *
     * @see self::resolveList() for _data blob query performance considerations.
     *
     * @return list<QueryFilter>
     */
    private function parseFilters(array $args): array
    {
        $filters = [];
        if (isset($args['filter']) && is_array($args['filter'])) {
            foreach ($args['filter'] as $f) {
                if (!is_array($f) || !isset($f['field'], $f['value'])) {
                    throw new UserError("Invalid filter: each entry must have 'field' and 'value' keys.");
                }
                $op = isset($f['operator']) ? strtoupper((string) $f['operator']) : '=';
                $allowed = ['=', '!=', '<', '>', '<=', '>=', 'CONTAINS', 'STARTS_WITH', 'IN'];
                if (!in_array($op, $allowed, true)) {
                    throw new UserError("Invalid filter operator: '{$f['operator']}'");
                }
                $value = $f['value'];
                if ($op === 'IN' && is_string($value)) {
                    $value = array_map('trim', explode(',', $value));
                }
                $filters[] = new QueryFilter(
                    field: (string) $f['field'],
                    value: $value,
                    operator: $op,
                );
            }
        }

        return $filters;
    }

    /**
     * @return list<QuerySort>
     */
    private function parseSorts(array $args): array
    {
        $sorts = [];
        if (isset($args['sort']) && is_string($args['sort'])) {
            foreach (explode(',', $args['sort']) as $sortField) {
                $sortField = trim($sortField);
                if ($sortField === '') {
                    continue;
                }
                $direction = 'ASC';
                if (str_starts_with($sortField, '-')) {
                    $direction = 'DESC';
                    $sortField = substr($sortField, 1);
                }
                $sorts[] = new QuerySort(field: $sortField, direction: $direction);
            }
        }

        return $sorts;
    }

    /**
     * Auto-inject account_id and tenant_id from the authenticated account
     * when the entity type defines those fields and the input omits them.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function injectAccountContext(string $entityTypeId, array $input): array
    {
        if ($this->account === null || !$this->account->isAuthenticated()) {
            return $input;
        }

        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $fieldDefinitions = $definition->getFieldDefinitions();

        if (isset($fieldDefinitions['account_id']) && !isset($input['account_id'])) {
            $input['account_id'] = (string) $this->account->id();
        }

        if (isset($fieldDefinitions['tenant_id']) && !isset($input['tenant_id'])) {
            $tenantId = $this->account->getTenantId();
            if ($tenantId !== null) {
                $input['tenant_id'] = $tenantId;
            }
        }

        return $input;
    }
}
