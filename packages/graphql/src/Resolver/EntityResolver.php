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
use Waaseyaa\Entity\Write\EntityWritePayloadGuard;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

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

    /**
     * Credential field names rejected as filter/sort fields regardless of whether
     * the entity type declares them. Mirrors
     * {@see \Waaseyaa\Api\JsonApiController::ALWAYS_INTERNAL_FIELDS} and
     * {@see \Waaseyaa\Api\ResourceSerializer::ALWAYS_INTERNAL_FIELDS} so the
     * GraphQL surface floors on the same credential keys as REST.
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

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

        // R15 (audit A11, structural sibling of R14): reject a filter/sort on any
        // field that is not a declared field or entity key, or that is a
        // credential / `internal`-flagged secret. The R14 gate below only sees
        // fields a FieldAccessPolicy Forbids; it cannot express the structural
        // classes REST's JsonApiController::validateQueryFields() rejects — an
        // arbitrary `_data` JSON key (which resolves to a raw json_extract sink
        // and a filter/sort presence oracle) or a declared `internal` field
        // (two_factor_secret, client_secret_hash) that carries no policy yet must
        // never be usable as an oracle. Runs BEFORE any storage query, value- and
        // account-independent, exactly like the REST allowlist.
        $this->assertQueryableFields($entityTypeId, $filters, $sorts);

        // R14 (audit A11): fields the caller filters/sorts on. A field can be
        // view-Forbidden for THIS account by a dynamic FieldAccessPolicy (a
        // classification / clearance field) while `canView()` (entity-level)
        // still admits the row, so the raw storage filter/sort turns `total`
        // and the item set into a presence/ordering oracle for a field the
        // caller may not read. Gated per entity below, value-independently.
        // Empty in system context (no bound account): that path keeps the raw
        // storage COUNT and does no field gating, exactly as before.
        $gatedQueryFields = $this->account !== null ? $this->queryFieldNames($filters, $sorts) : [];

        // R14 (audit A11): reject a SORT on a field the caller may not read on
        // some matched row. The value-independent drop below closes the filter
        // oracle and keeps the value off the wire, but sort()/range() run in
        // storage BEFORE the drop, so a forbidden row still occupies an
        // observable pagination RANK (empty-vs-populated page across offsets =
        // ordering oracle). Fail the sort closed; the decision is
        // value-independent (depends only on which viewable rows carry a
        // Forbidden sort field). Mirrors JsonApiController::rejectForbiddenSort().
        $this->rejectForbiddenSort($repository, $filters, $sorts);

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
                    if ($this->guard->canView($countEntity)
                        && !$this->queryFieldForbidden($countEntity, $gatedQueryFields)) {
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
            // R14: value-independent exclusion — a row whose filter/sort field
            // the caller may not read never surfaces, so its position/presence
            // cannot encode the hidden value. Mirrors the count loop above and
            // JsonApiController::index()'s per-entity gate.
            if ($this->queryFieldForbidden($entity, $gatedQueryFields)) {
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

        // CW-v1 option-1 PR-4 (findings #1/#2), defense-in-depth: the
        // generated GraphQL input type already bounds this surface, but the
        // shared guard closes the class of hole by construction rather than
        // per-schema. Runs BEFORE create()/save() — nothing is persisted on
        // refusal. Mirrors JsonApiController::store().
        $this->assertWritable($entityTypeId, $this->resolveBundle($entityTypeId, $input), $input);

        // C-22 WP3: create/save now go through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $entity = $repository->create($input);

        foreach (array_keys($input) as $fieldName) {
            $this->guard->assertFieldEditAccess($entity, $fieldName);
        }

        if ($entity instanceof EntityBase) {
            $entity->enforceIsNew();
        }

        // CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): WorkflowStateGuard
        // denies from PRE_SAVE inside save() for workflow-bound types — without
        // this catch, webonyx/graphql-php masks the denial as a generic
        // "Internal server error" (TransitionDeniedException is not
        // ClientAware). Re-thrown as UserError so the real, actionable denial
        // reason reaches the caller, mirroring JsonApiController's REST mapping.
        try {
            $repository->save($entity);
        } catch (TransitionDeniedException $e) {
            throw new UserError($e->getMessage());
        }

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

        // R11 (audit A9, defense-in-depth): collapse "access denied" into the SAME
        // not-found error thrown above for an absent entity. Without this, an
        // authenticated-but-unauthorized caller could distinguish "this id does
        // not exist" from "this id exists but you may not modify it" by diffing
        // the two error messages -- an existence oracle over every entity id,
        // independent of the endpoint-level anonymous-mutation gate (which only
        // covers UNauthenticated callers). Mirrors the resolveSingle() read path,
        // which has always returned null for both cases uniformly.
        //
        // The FIELD-edit loop is inside this same try/catch on purpose (R11
        // follow-up). Entity-level `update` can be ALLOWED while a specific field's
        // `edit` is FORBIDDEN (e.g. NodeAccessPolicy grants `edit any {type}
        // content` at the entity level but field-forbids `uid`/`created`/`changed`
        // for non-admins). Those field denials fire only for a REAL entity (the
        // absent branch returned "not found" above), so a distinguishable
        // "cannot edit field" message would re-open the exact same existence oracle
        // for any ordinary editor. Both access-guard calls therefore collapse to
        // the identical not-found error. Only the two access checks belong inside:
        // the FieldableInterface support check and the set()/save() field-VALIDATION
        // below stay OUTSIDE, so a genuine validation/support error for an
        // AUTHORIZED caller is surfaced accurately and never masked as "not found".
        try {
            $this->guard->assertUpdateAccess($entity);
        } catch (UserError) {
            throw new UserError("Entity not found: {$entityTypeId}/{$id}");
        }

        // CW-v1 option-1 PR-4 (findings #1/#2) rework, defense-in-depth: the
        // echo-tolerant companion to resolveCreate()'s hard
        // assertWritable()/JsonApiController::update()'s
        // EntityWritePayloadGuard::evaluateForUpdate() call. Runs only after
        // update access is confirmed above (so it adds no existence oracle:
        // the refusal depends only on the entity TYPE's schema, not this
        // entity instance or the caller's access), BEFORE the field-access
        // loop below (so an allowed echo of a bookkeeping column a site
        // policy happens to field-forbid never 403s spuriously — parity with
        // JsonApiController::update()'s strip-before-field-access ordering),
        // and BEFORE any set()/save() — nothing is applied on refusal. An
        // allowed echo (submitted value equals the entity's current stored
        // value for an identity/bookkeeping column, e.g.
        // `revision_id`/`published_revision_id` — FR-008 documents these as
        // load-bearing READ attributes a read-modify-write client
        // legitimately echoes back) is stripped from `$input` here, before
        // the apply loop below (belt: an allowed echo must never reach
        // `$entity->set()`).
        $input = $this->assertWritableForUpdate($entityTypeId, $entity->bundle(), $input, $entity->toArray());

        try {
            foreach (array_keys($input) as $fieldName) {
                $this->guard->assertFieldEditAccess($entity, $fieldName);
            }
        } catch (UserError) {
            throw new UserError("Entity not found: {$entityTypeId}/{$id}");
        }

        if (!$entity instanceof FieldableInterface) {
            throw new UserError("Entity type '{$entityTypeId}' does not support field updates.");
        }
        foreach ($input as $field => $value) {
            $entity->set($field, $value);
        }

        // C-22 WP3: save path now goes through the canonical repository.
        // CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): same
        // TransitionDeniedException -> UserError mapping as resolveCreate()
        // above — this is exactly the "same-state edit of published
        // content now requires any-of authorization" surface the design
        // makes routine.
        try {
            $this->entityTypeManager->getRepository($entityTypeId)->save($entity);
        } catch (TransitionDeniedException $e) {
            throw new UserError($e->getMessage());
        }

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

        // R11 (audit A9, defense-in-depth): same not-found collapse as resolveUpdate() above.
        try {
            $this->guard->assertDeleteAccess($entity);
        } catch (UserError) {
            throw new UserError("Entity not found: {$entityTypeId}/{$id}");
        }

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
     * Reject (UserError) a filter/sort on a structurally-impermissible field
     * (R15, audit A11). The GraphQL companion to REST's
     * {@see \Waaseyaa\Api\JsonApiController::validateQueryFields()}.
     *
     * A field is rejected when it is:
     *   - neither a declared field of the entity type nor an entity key, OR
     *   - a credential name in {@see self::ALWAYS_INTERNAL_FIELDS}, OR
     *   - a declared field flagged `settings['internal'] => true`.
     *
     * This is a static, value-independent allowlist run before any storage
     * query. It closes the two oracle classes R14 cannot see: an undeclared
     * `_data` JSON key (raw json_extract sink) and a declared `internal` secret
     * that carries no FieldAccessPolicy. Per-row classification/clearance fields
     * (declared, non-internal) still pass here and are handled value-independently
     * by {@see queryFieldForbidden()} / {@see rejectForbiddenSort()}.
     *
     * @param list<QueryFilter> $filters
     * @param list<QuerySort>   $sorts
     */
    private function assertQueryableFields(string $entityTypeId, array $filters, array $sorts): void
    {
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId);
        $keys = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys();

        /** @var array<string, true> $allowedFields */
        $allowedFields = array_fill_keys(array_keys($fieldDefinitions), true)
            + array_fill_keys(array_values($keys), true);

        $isRejected = static function (string $field) use ($allowedFields, $fieldDefinitions): bool {
            if (!isset($allowedFields[$field])) {
                return true;
            }
            if (in_array($field, self::ALWAYS_INTERNAL_FIELDS, true)) {
                return true;
            }
            $definition = $fieldDefinitions[$field] ?? null;

            return $definition !== null && $definition->getSetting('internal') === true;
        };

        foreach ($filters as $filter) {
            if ($isRejected($filter->field)) {
                throw new UserError("Cannot filter by field '{$filter->field}'.");
            }
        }

        foreach ($sorts as $sort) {
            if ($isRejected($sort->field)) {
                throw new UserError("Cannot sort by field '{$sort->field}'.");
            }
        }
    }

    /**
     * The distinct field names a list query filters or sorts on (R14).
     *
     * @param list<QueryFilter> $filters
     * @param list<QuerySort>   $sorts
     * @return list<string>
     */
    private function queryFieldNames(array $filters, array $sorts): array
    {
        $fields = [];
        foreach ($filters as $filter) {
            $fields[$filter->field] = true;
        }
        foreach ($sorts as $sort) {
            $fields[$sort->field] = true;
        }

        return array_keys($fields);
    }

    /**
     * Reject (UserError) a list query that sorts on a field the caller may not
     * read on some entity-level-viewable matched row (R14, audit A11).
     *
     * The pagination-position companion to {@see queryFieldForbidden()}: that
     * drop keeps a forbidden field's VALUE off the wire, but sort()/range() run
     * in storage over the full match set BEFORE the drop, so a forbidden row
     * still occupies a sort RANK whose empty pagination slot leaks its ordering.
     * Storage cannot evaluate per-row field access, so the fail-closed fix is to
     * refuse the sort. Value-independent (depends only on WHICH viewable rows
     * carry a Forbidden sort field), so it adds no oracle beyond resolveSingle()'s
     * existing per-row field-read boundary. No sort / no bound account short-circuits.
     *
     * @param list<QueryFilter> $filters
     * @param list<QuerySort>   $sorts
     */
    private function rejectForbiddenSort(
        \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository,
        array $filters,
        array $sorts,
    ): void {
        if ($sorts === [] || $this->account === null) {
            return;
        }

        $idQuery = $repository->getQuery()->setAccount($this->account);
        foreach ($filters as $filter) {
            $idQuery->condition($filter->field, $filter->value, $filter->operator);
        }
        $ids = $idQuery->execute();
        if ($ids === []) {
            return;
        }

        foreach ($repository->findMany($ids) as $entity) {
            if (!$this->guard->canView($entity)) {
                continue;
            }
            foreach ($sorts as $sort) {
                if ($this->guard->isFieldViewForbidden($entity, $sort->field)) {
                    throw new UserError("Cannot sort by field '{$sort->field}'.");
                }
            }
        }
    }

    /**
     * True when ANY caller-supplied filter/sort field is view-Forbidden for
     * this entity (R14, audit A11). Value-independent, evaluated per entity —
     * see {@see GraphQlAccessGuard::isFieldViewForbidden()}.
     *
     * @param list<string> $gatedQueryFields
     */
    private function queryFieldForbidden(EntityInterface $entity, array $gatedQueryFields): bool
    {
        foreach ($gatedQueryFields as $field) {
            if ($this->guard->isFieldViewForbidden($entity, $field)) {
                return true;
            }
        }

        return false;
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

    /**
     * The bundle value for a create input, resolved from the entity type's
     * own bundle key (mirrors `JsonApiController::store()`'s bundle
     * resolution) — used only to scope
     * {@see EntityWritePayloadGuard::refusedKeys()}'s bundle-aware field
     * lookup, never to validate the bundle itself.
     *
     * @param array<string, mixed> $input
     */
    private function resolveBundle(string $entityTypeId, array $input): string
    {
        $bundleKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys()['bundle'] ?? null;

        return $bundleKey !== null ? (string) ($input[$bundleKey] ?? '') : '';
    }

    /**
     * CW-v1 option-1 PR-4 (findings #1/#2): reject (UserError) an input key
     * that is neither a declared field nor a writable entity key, or that is
     * an identity/bookkeeping column (`revision_id`, `published_revision_id`,
     * ...) regardless of declaration. Defense-in-depth alongside the
     * generated GraphQL input type's own schema bound.
     *
     * @param array<string, mixed> $input
     */
    private function assertWritable(string $entityTypeId, string $bundle, array $input): void
    {
        $refused = EntityWritePayloadGuard::refusedKeys(
            $this->entityTypeManager->getDefinition($entityTypeId),
            $bundle,
            array_keys($input),
            $this->entityTypeManager,
        );
        if ($refused !== []) {
            throw new UserError(sprintf(
                'The following input field(s) are not writable: %s.',
                implode(', ', $refused),
            ));
        }
    }

    /**
     * The echo-tolerant companion to {@see self::assertWritable()}, used only
     * by {@see self::resolveUpdate()} (PR-4 rework). An identity/bookkeeping
     * key whose submitted value equals `$currentValues`' stored value for
     * that key (type-lenient comparison, see
     * {@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard::evaluateForUpdate()})
     * is an allowed echo — not refused, but also stripped from the returned
     * input so it can never reach `$entity->set()`. A genuinely different
     * value, or an undeclared/unknown field, is still refused (`UserError`).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $currentValues the target entity's current stored values ({@see \Waaseyaa\Entity\EntityInterface::toArray()})
     * @return array<string, mixed> $input with every allowed-echo key removed
     */
    private function assertWritableForUpdate(string $entityTypeId, string $bundle, array $input, array $currentValues): array
    {
        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $this->entityTypeManager->getDefinition($entityTypeId),
            $bundle,
            $input,
            $this->entityTypeManager,
            $currentValues,
        );
        if ($result->refusedKeys !== []) {
            throw new UserError(sprintf(
                'The following input field(s) are not writable: %s.',
                implode(', ', $result->refusedKeys),
            ));
        }

        foreach ($result->echoedKeys as $echoedKey) {
            unset($input[$echoedKey]);
        }

        return $input;
    }
}
