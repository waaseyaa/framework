<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Resolver;

use GraphQL\Error\UserError;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Query\ParsedQuery;
use Waaseyaa\Api\Query\QueryApplier;
use Waaseyaa\Api\Query\QueryFilter;
use Waaseyaa\Api\Query\QuerySort;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
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
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);

        $filters = $this->parseFilters($args);
        $sorts = $this->parseSorts($args);
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $limit = isset($args['limit']) ? min(self::MAX_LIMIT, max(1, (int) $args['limit'])) : self::DEFAULT_LIMIT;

        // Total — filters only, no sorts/pagination.
        $countQuery = $repository->getQuery();
        if ($this->account !== null) {
            // Access-filtered total (#1702, audit C-7). The query layer is
            // open-by-default (admits Allowed AND Neutral), but `items` below are
            // deny-by-default via guard->canView(); taking `total` from the raw
            // storage COUNT therefore leaks the unfiltered collection cardinality
            // (Neutral/policy-less rows inflate it) while those rows never appear
            // in `items`. Recompute `total` across ALL matching rows with the
            // SAME guard->canView() predicate as the per-item filter, so the two
            // reconcile across pages — mirroring JsonApiController::accessFilteredTotal
            // for the REST collection.
            $countQuery = $countQuery->setAccount($this->account);
            foreach ($filters as $filter) {
                $countQuery->condition($filter->field, $filter->value, $filter->operator);
            }
            $countIds = $countQuery->execute();
            $total = 0;
            if ($countIds !== []) {
                foreach ($repository->findMany($countIds) as $countEntity) {
                    if ($this->guard->canView($countEntity)) {
                        ++$total;
                    }
                }
            }
        } else {
            // System context (no bound account — internal tooling such as
            // background ingestion / sitemap build): the resolver routes through
            // accessCheck(false) and the unfiltered storage COUNT is the documented
            // total (GraphQLResolverFilterTest::systemContextBypass...).
            $countQuery = $countQuery->accessCheck(false);
            foreach ($filters as $filter) {
                $countQuery->condition($filter->field, $filter->value, $filter->operator);
            }
            $countQuery->count();
            $countResult = $countQuery->execute();
            $total = (int) ($countResult[0] ?? 0);
        }

        // Main query via QueryApplier — access enforced at query layer.
        $parsedQuery = new ParsedQuery(
            filters: $filters,
            sorts: $sorts,
            offset: $offset,
            limit: $limit,
        );
        $applier = new QueryApplier();
        $baseQuery = $repository->getQuery();
        if ($this->account !== null) {
            $baseQuery = $baseQuery->setAccount($this->account);
        } else {
            // system context: resolver invoked without an account in scope (e.g. internal tooling)
            $baseQuery = $baseQuery->accessCheck(false);
        }
        $mainQuery = $applier->apply($parsedQuery, $baseQuery);
        $ids = $mainQuery->execute();

        $entities = $ids !== [] ? $repository->findMany($ids) : [];

        // Post-fetch access filtering
        $items = [];
        foreach ($entities as $entity) {
            if (!$this->guard->canView($entity)) {
                continue;
            }
            $values = EntityValues::toCastAwareMap($entity);
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

        $values = EntityValues::toCastAwareMap($entity);
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

        // C-22 WP3: create/save now go through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $entity = $repository->create($input);

        foreach (array_keys($input) as $fieldName) {
            $this->guard->assertFieldEditAccess($entity, $fieldName);
        }

        if ($entity instanceof EntityBase) {
            $entity->enforceIsNew();
        }
        $repository->save($entity);

        $values = EntityValues::toCastAwareMap($entity);
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

        // C-22 WP3: save path now goes through the canonical repository.
        $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

        $values = EntityValues::toCastAwareMap($entity);
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

        // C-22 WP3: delete path now goes through the canonical repository.
        $this->entityTypeManager->getRepository($entityTypeId)->delete($entity);

        return true;
    }

    private function loadEntity(string $entityTypeId, int|string $id): ?EntityInterface
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        // UUID detection — same pattern as JsonApiController::loadByIdOrUuid
        if (is_string($id) && isset($keys['uuid'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
        ) {
            $uuidQuery = $repository->getQuery()->condition($keys['uuid'], $id);
            if ($this->account !== null) {
                $uuidQuery = $uuidQuery->setAccount($this->account);
            } else {
                // system context: resolver invoked without an account in scope
                $uuidQuery = $uuidQuery->accessCheck(false);
            }
            $ids = $uuidQuery->execute();
            if ($ids === []) {
                return null;
            }
            return $repository->find((string) reset($ids));
        }

        return $repository->find((string) $id);
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

        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId);

        if (isset($fieldDefinitions['account_id']) && !isset($input['account_id'])) {
            $input['account_id'] = (string) $this->account->id();
        }

        return $input;
    }
}
