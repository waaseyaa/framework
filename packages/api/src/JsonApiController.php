<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Query\PaginationLinks;
use Waaseyaa\Api\Query\ParsedQuery;
use Waaseyaa\Api\Query\QueryApplier;
use Waaseyaa\Api\Query\QueryParser;
use Waaseyaa\Entity\ConfigEntityInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Write\EntityWritePayloadGuard;
use Waaseyaa\Entity\Write\EntityWritePayloadGuardResult;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * Handles JSON:API CRUD operations.
 *
 * This is a plain PHP class that receives parsed parameters and returns
 * JsonApiDocument objects. It is not tied to any HTTP framework.
 */
final class JsonApiController
{
    /**
     * Credential keys that must never be queryable, even when stored as a raw `_data` key
     * with no FieldDefinition. Mirrors {@see ResourceSerializer::ALWAYS_INTERNAL_FIELDS}.
     *
     * @var list<string>
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}

    /**
     * GET collection — list entities of a given type.
     *
     * @param string               $entityTypeId The entity type to list.
     * @param array<string, mixed> $query        Optional query parameters (filter, sort, page, fields).
     */
    public function index(string $entityTypeId, array $query = []): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);

        // Parse query parameters.
        $parser = new QueryParser();
        $parsedQuery = $parser->parse($query);

        // Validate filter/sort field names against the declared-field allowlist (audit R2 WP1).
        // Without this, an anonymous collection request could pass an arbitrary query-string
        // key straight through QueryParser -> QueryApplier -> SqlEntityQuery::resolveField(),
        // which interpolates the field name RAW into a json_extract('$.<field>') SQL fragment.
        // A field name containing a single quote breaks out of that string literal — anonymous
        // SQL injection. Only a declared field (resolveFieldDefinitions()) or an entity key
        // (id/uuid/label/bundle/langcode/...) may be filtered or sorted on; everything else is
        // rejected, even before the internal/credential check below.
        $queryFieldError = $this->validateQueryFields($parsedQuery, $entityTypeId);
        if ($queryFieldError !== null) {
            return $queryFieldError;
        }

        // R14 (audit A11): reject a SORT on a field the caller may not read on
        // some matched row. The value-independent per-entity drop below closes
        // the filter oracle and the field's VALUE never reaches the wire, but
        // `sort()`/`range()` run in storage BEFORE that drop, so a forbidden
        // row still occupies a pagination RANK: scanning offsets with a small
        // page turns the empty-vs-populated pattern into an ordering oracle on
        // the hidden value. Failing the sort closed is the value-independent
        // fix (the reject depends only on WHICH rows the caller may field-read,
        // never on the field's value or the sort direction), and avoids moving
        // sort/pagination out of storage.
        $sortRejection = $this->rejectForbiddenSort($repository, $parsedQuery);
        if ($sortRejection !== null) {
            return $sortRejection;
        }

        $applier = new QueryApplier();

        // Count total matching entities (before pagination). Bind the request's
        // authenticated account so the query layer filters access at source.
        $countQuery = $repository->getQuery();
        if ($this->account !== null) {
            $countQuery->setAccount($this->account);
        } else {
            // system context: controller invoked without an account in scope
            $countQuery->accessCheck(false);
        }
        // Apply only filters to the count query (not sorts/pagination).
        foreach ($parsedQuery->filters as $filter) {
            $countQuery->condition($filter->field, $filter->value, $filter->operator);
        }
        $countQuery->count();
        $countResult = $countQuery->execute();
        $total = (int) ($countResult[0] ?? 0);

        // Build and execute the main query with filters, sorts, and pagination.
        $entityQuery = $repository->getQuery();
        if ($this->account !== null) {
            $entityQuery->setAccount($this->account);
        } else {
            // system context: controller invoked without an account in scope
            $entityQuery->accessCheck(false);
        }
        $applier->apply($parsedQuery, $entityQuery);

        $ids = $entityQuery->execute();
        $entities = $ids !== [] ? $repository->findMany($ids) : [];

        // R14 (audit A11): the fields a caller filters or sorts on. A field can
        // pass validateQueryFields() (declared, not internal) yet be view-Forbidden
        // for THIS account by a dynamic FieldAccessPolicy (e.g. a classification /
        // clearance field). The raw storage filter/sort still evaluates its value,
        // so meta.total and the row set become a presence/ordering oracle for a
        // field the caller may not read. Gate them per entity below, fail closed.
        $gatedQueryFields = $this->queryFieldNames($parsedQuery);

        // Filter the current page by view access if an access handler is
        // available. Entity-level access is deny-by-default (isAllowed): a
        // Neutral row is not visible. This mirrors show() (single read).
        if ($this->accessHandler !== null && $this->account !== null) {
            $entities = array_filter(
                $entities,
                fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed()
                    && !$this->queryFieldForbidden($entity, $gatedQueryFields),
            );
            // meta.total must reflect the access-filtered total ACROSS all
            // pages, not the size of the current page. The storage COUNT alone
            // is the wrong source here: the query layer drops only Forbidden
            // rows (open-by-default), whereas the collection contract is
            // deny-by-default (isAllowed), so Neutral rows would inflate it.
            // Recompute the true total by re-running the filter set without
            // pagination and counting rows this account may actually view AND
            // whose filter/sort fields it may read (R14).
            $total = $this->accessFilteredTotal($repository, $parsedQuery, $gatedQueryFields);
        }

        $resources = $this->serializer->serializeCollection($entities, $this->accessHandler, $this->account);

        // Apply sparse fieldsets if requested (attributes and relationships per JSON:API).
        if (isset($parsedQuery->sparseFieldsets[$entityTypeId])) {
            $allowedFields = $parsedQuery->sparseFieldsets[$entityTypeId];
            $resources = array_map(
                static fn(JsonApiResource $resource): JsonApiResource => SparseFieldsetApplicator::apply(
                    $resource,
                    $allowedFields,
                ),
                $resources,
            );
        }

        // Generate pagination links and meta.
        $offset = $applier->getEffectiveOffset($parsedQuery);
        $limit = $applier->getEffectiveLimit($parsedQuery);
        $basePath = "/api/{$entityTypeId}";
        $links = PaginationLinks::generate($basePath, $offset, $limit, $total);

        $meta = [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ];

        return JsonApiDocument::fromCollection(
            $resources,
            links: $links,
            meta: $meta,
        );
    }

    /**
     * Count, across all pages, the rows matching the query's filters that the
     * current account may view under deny-by-default entity-level semantics.
     *
     * This applies the SAME `isAllowed()` predicate as the per-page filter in
     * {@see index()} (and as {@see show()}), so meta.total is consistent with
     * the data the consumer receives over successive pages — never the page
     * size, never the open-by-default storage COUNT. Filters only: sorts and
     * pagination are intentionally omitted.
     *
     * Only invoked when both an access handler and an account are bound; the
     * system / no-account path keeps the storage COUNT computed in index().
     *
     * @param \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository
     * @param list<string> $gatedQueryFields Filter/sort field names to gate through
     *                                        field-level view access (R14). An entity
     *                                        with any of these Forbidden is excluded
     *                                        value-independently, so a probed value can
     *                                        never move the count.
     */
    private function accessFilteredTotal(
        \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository,
        ParsedQuery $parsedQuery,
        array $gatedQueryFields = [],
    ): int {
        \assert($this->accessHandler !== null && $this->account !== null);

        $idQuery = $repository->getQuery();
        $idQuery->setAccount($this->account);
        // Filters only — no sort, no range — so we span the whole match set.
        foreach ($parsedQuery->filters as $filter) {
            $idQuery->condition($filter->field, $filter->value, $filter->operator);
        }

        $ids = $idQuery->execute();
        if ($ids === []) {
            return 0;
        }

        $total = 0;
        foreach ($repository->findMany($ids) as $entity) {
            if ($this->accessHandler->check($entity, 'view', $this->account)->isAllowed()
                && !$this->queryFieldForbidden($entity, $gatedQueryFields)) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * The distinct field names a collection request filters or sorts on.
     *
     * @return list<string>
     */
    private function queryFieldNames(ParsedQuery $parsedQuery): array
    {
        $fields = [];
        foreach ($parsedQuery->filters as $filter) {
            $fields[$filter->field] = true;
        }
        foreach ($parsedQuery->sorts as $sort) {
            $fields[$sort->field] = true;
        }

        return array_keys($fields);
    }

    /**
     * Reject (400) a collection request that sorts on a field the caller may
     * not read on some entity-level-viewable matched row (R14, audit A11).
     *
     * This is the pagination-position companion to {@see queryFieldForbidden()}:
     * that drop keeps a forbidden field's VALUE off the wire, but `sort()` and
     * `range()` execute in storage over the full match set BEFORE the drop, so
     * a forbidden row still occupies a sort RANK and its empty pagination slot
     * leaks its ordering relative to readable rows. Because storage cannot
     * evaluate per-row field-access policy, the fail-closed fix is to refuse the
     * sort rather than order rows the caller cannot fully read.
     *
     * The decision is VALUE-INDEPENDENT: it depends only on which viewable rows
     * carry a Forbidden sort field, never on the field's value or the sort
     * direction, so it adds no oracle beyond what {@see show()} already exposes
     * (a per-row "you may not read this field" boundary — the caller's own
     * clearance). No sort, no account, or an all-readable sort field returns
     * null and the request proceeds unchanged.
     *
     * @param \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository
     */
    private function rejectForbiddenSort(
        \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository,
        ParsedQuery $parsedQuery,
    ): ?JsonApiDocument {
        if ($parsedQuery->sorts === [] || $this->accessHandler === null || $this->account === null) {
            return null;
        }

        // The entity-level-viewable rows matching the filters (no sort, no range
        // — span the whole match set the sort would order).
        $idQuery = $repository->getQuery();
        $idQuery->setAccount($this->account);
        foreach ($parsedQuery->filters as $filter) {
            $idQuery->condition($filter->field, $filter->value, $filter->operator);
        }
        $ids = $idQuery->execute();
        if ($ids === []) {
            return null;
        }

        foreach ($repository->findMany($ids) as $entity) {
            if (!$this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
                continue;
            }
            foreach ($parsedQuery->sorts as $sort) {
                if ($this->accessHandler->checkFieldAccess($entity, $sort->field, 'view', $this->account)->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::badRequest("Cannot sort by field '{$sort->field}'."),
                    );
                }
            }
        }

        return null;
    }

    /**
     * True when ANY of the caller's filter/sort fields is view-Forbidden for
     * this entity (R14, audit A11).
     *
     * The exclusion is value-independent: an entity is dropped because the
     * caller may not READ the field it filtered/sorted on, never because of the
     * field's value, so no operator (including NOT_EQUALS) and no probe value
     * can turn the row set or meta.total into a presence/ordering oracle. This
     * is the per-entity companion to the structural {@see validateQueryFields()}
     * allowlist, mirroring R13 WP1's admin-surface shape: a field can be
     * Forbidden only for SOME entities of the type (classification/clearance
     * gating varies per row), which a static allowlist cannot express.
     *
     * Only reached on the access-handler+account path; the no-account system
     * context keeps the storage-derived total computed in {@see index()}.
     *
     * @param list<string> $gatedQueryFields
     */
    private function queryFieldForbidden(EntityInterface $entity, array $gatedQueryFields): bool
    {
        if ($gatedQueryFields === [] || $this->accessHandler === null || $this->account === null) {
            return false;
        }

        foreach ($gatedQueryFields as $field) {
            if ($this->accessHandler->checkFieldAccess($entity, $field, 'view', $this->account)->isForbidden()) {
                return true;
            }
        }

        return false;
    }

    /**
     * GET single — retrieve a specific entity.
     *
     * @param string               $entityTypeId The entity type.
     * @param int|string           $id           The entity ID.
     * @param array<string, mixed> $query        Query parameters (supports 'fields' for sparse
     *                                            fieldsets and CW-v1 option-1's `workingCopy`).
     */
    public function show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        $entity = $this->loadByIdOrUuid($entityTypeId, $id);

        if ($entity === null) {
            return $this->notFoundDocument($entityTypeId, $id);
        }

        // Check view access. A denied view returns the same not-found document
        // as a missing entity so the response cannot act as an existence oracle.
        if ($this->accessHandler !== null && $this->account !== null) {
            $access = $this->accessHandler->check($entity, 'view', $this->account);
            if (!$access->isAllowed()) {
                return $this->notFoundDocument($entityTypeId, $id);
            }
        }

        // CW-v1 option-1 (#1920 PR-3, design §4): `?workingCopy=1` serves the
        // entity's WORKING COPY (the tip revision) instead of the published
        // pointer `find()` above already resolved. This is a SEPARATE gate
        // from the view check above — entity UPDATE access, not view — and
        // is checked only once the view gate has passed, so denial here is a
        // plain 403 (JsonApiError::forbidden), never an existence oracle: a
        // missing/view-denied entity already exited via the canonical 404
        // above. When no draft exists `loadWorkingCopy() === find()`
        // (mechanically safe on any entity type — undisciplined ones and
        // disciplined-but-undrafted ones both degrade to `find()`), so the
        // response equals the plain GET byte-for-byte (pinned by test).
        if ($this->workingCopyRequested($query)) {
            if ($this->accessHandler === null || $this->account === null
                || !$this->accessHandler->check($entity, 'update', $this->account)->isAllowed()
            ) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for viewing the working copy of entity '{$id}'."),
                );
            }

            $repository = $this->entityTypeManager->getRepository($entityTypeId);
            $entity = $repository->loadWorkingCopy((string) $entity->id()) ?? $entity;
        }

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

        // Apply sparse fieldsets per JSON:API spec (attributes and relationships).
        $parsedQuery = new QueryParser()->parse($query);
        if (isset($parsedQuery->sparseFieldsets[$entityTypeId])) {
            $allowedFields = $parsedQuery->sparseFieldsets[$entityTypeId];
            $resource = SparseFieldsetApplicator::apply($resource, $allowedFields);
        }

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
        );
    }

    /**
     * True when the request asked for the working copy via `?workingCopy=1`
     * (CW-v1 option-1, #1920 PR-3). Accepts the same truthy shapes
     * {@see \Waaseyaa\SSR\SsrPageHandler::isPreviewRequested()} does for its
     * analogous `?preview` toggle, since both arrive through the same
     * `Request::query->all()` -> plain-array query-param seam.
     *
     * @param array<string, mixed> $query
     */
    private function workingCopyRequested(array $query): bool
    {
        $value = $query['workingCopy'] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    /**
     * POST — create a new entity.
     *
     * @param string               $entityTypeId The entity type.
     * @param array<string, mixed> $data         The full JSON:API request body (expects 'data.type' and optionally 'data.attributes').
     */
    public function store(string $entityTypeId, array $data): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        // Validate request data structure.
        if (!isset($data['data']) || !isset($data['data']['type'])) {
            return $this->errorDocument(
                JsonApiError::badRequest('Missing required "data" object with "type" member.'),
            );
        }

        if ($data['data']['type'] !== $entityTypeId) {
            return $this->errorDocument(
                JsonApiError::unprocessable(
                    "Resource type '{$data['data']['type']}' does not match endpoint type '{$entityTypeId}'.",
                ),
            );
        }

        $attributes = $data['data']['attributes'] ?? [];

        // Validate required fields for content entities.
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        // Bundle validation: if bundle key is explicitly provided but empty, reject it.
        $bundleKey = $keys['bundle'] ?? null;
        if ($bundleKey !== null && isset($keys['uuid'])
            && array_key_exists($bundleKey, $attributes) && trim((string) $attributes[$bundleKey]) === '') {
            return $this->errorDocument(
                JsonApiError::unprocessable(
                    "The '{$bundleKey}' attribute cannot be empty for {$entityTypeId} entities.",
                ),
            );
        }

        // Label validation: if entity type has a label key, require non-empty value.
        $labelKey = $keys['label'] ?? null;
        if ($labelKey !== null && array_key_exists($labelKey, $attributes)) {
            $labelValue = trim((string) ($attributes[$labelKey] ?? ''));
            if ($labelValue === '') {
                return $this->errorDocument(
                    JsonApiError::unprocessable(
                        "The '{$labelKey}' field cannot be empty.",
                    ),
                );
            }
        }

        // Auto-generate machine name for config entities if ID is empty.
        // Config types can still expose UUID, so we treat same-id-and-bundle mappings as config-style
        // entities (e.g. node_type: id=type, bundle=type) while keeping content entities like node
        // (id=nid, bundle=type) on numeric/uuid identity semantics.
        $idKey = $keys['id'] ?? 'id';
        $bundleMatchesId = isset($keys['bundle']) && $keys['bundle'] === $idKey;
        $nonDefaultIdWithoutBundle = $idKey !== 'id' && !isset($keys['bundle']);
        $usesConfigMachineIds = $bundleMatchesId || $nonDefaultIdWithoutBundle || !isset($keys['uuid']);
        if ($usesConfigMachineIds) {
            $configLabelKey = $keys['label'] ?? 'label';
            if ((!isset($attributes[$idKey]) || $attributes[$idKey] === '')
                && isset($attributes[$configLabelKey]) && $attributes[$configLabelKey] !== '') {
                $machineName = self::toMachineName((string) $attributes[$configLabelKey]);
                if ($machineName === '') {
                    return $this->errorDocument(
                        JsonApiError::unprocessable(
                            "Cannot generate a machine name from label '{$attributes[$configLabelKey]}'. "
                            . 'Provide an explicit ID or use a label with alphanumeric characters.',
                        ),
                    );
                }
                $attributes[$idKey] = $machineName;
            }
        }

        // CW-v1 option-1 design §5 (findings #1/#2): reject-not-strip a
        // payload key that is neither a declared field nor a writable entity
        // key, or that is an identity/bookkeeping column (revision_id,
        // published_revision_id, uuid, ...) regardless of declaration. Runs
        // BEFORE create()/save() — nothing is persisted on refusal.
        //
        // Config-style entities (e.g. node_type) use the id key as a
        // deliberately client-settable machine name at create time — a
        // pre-existing, tested contract (JsonApiControllerConfigEntityTest,
        // the $usesConfigMachineIds branch above). The guard's identity-kind
        // refusal exists to stop a numeric/uuid PRIMARY KEY from being
        // client-forged on a content entity; exempting the resolved id key
        // here (rather than weakening the guard for every entity type) keeps
        // that existing behavior while still refusing it for ordinary
        // content entities (Node's `nid`, for example, is never a declared
        // field or a writable key, so it is refused via the general branch).
        $guardKeys = array_keys($attributes);
        if ($usesConfigMachineIds) {
            $guardKeys = array_values(array_diff($guardKeys, [$idKey]));
        }
        $guardBundle = $bundleKey !== null ? (string) ($attributes[$bundleKey] ?? '') : '';
        $refusedKeys = EntityWritePayloadGuard::refusedKeys($definition, $guardBundle, $guardKeys, $this->entityTypeManager);
        if ($refusedKeys !== []) {
            return $this->errorDocument($this->writeAllowlistError($refusedKeys));
        }

        // C-22 WP3: create/save now go through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $entity = $repository->create($attributes);

        // Authored entities created through JSON:API (including the generic
        // admin host, which delegates here) belong to the authenticated
        // creator when their declared shape has a `uid` owner field and the
        // client did not explicitly choose an author. This covers node, media,
        // note, and future authored quick-entry types without a type-id list.
        // Explicit create-time uid assignment remains subject to each type's
        // field policy (for administrator create-on-behalf workflows).
        $uidDefinition = $definition->getFieldDefinitions()['uid'] ?? null;
        if ($uidDefinition?->getSetting('authorizationInput') === true
            && ($keys['id'] ?? 'id') !== 'uid'
            && !\array_key_exists('uid', $attributes)
            && $entity instanceof FieldableInterface
            && $this->account?->isAuthenticated() === true
        ) {
            $accountId = $this->account->id();
            if (\is_int($accountId) || \ctype_digit($accountId)) {
                $entity->set('uid', (int) $accountId);
            }
        }

        // Check create access.
        if ($this->accessHandler !== null && $this->account !== null) {
            // Pre-existing bug fix, found while adding the guard above: this
            // used to read the LITERAL attribute key 'bundle' (`$attributes['bundle']
            // ?? $entityTypeId`), which is almost never the entity type's real
            // bundle key (node's is 'type') — so per-bundle create permissions
            // (e.g. NodeAccessPolicy's `create article content`) silently
            // checked `create node content` instead for any real client that
            // never happened to send a bogus 'bundle' attribute alongside the
            // real one. $guardBundle (computed above from the entity type's
            // OWN bundle key) is the correct value; reuse it here rather than
            // duplicating a second, buggy bundle resolution.
            $bundle = $guardBundle !== '' ? $guardBundle : $entityTypeId;
            $access = $this->accessHandler->checkCreateAccess($entityTypeId, $bundle, $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for creating entity of type '{$entityTypeId}'."),
                );
            }

            // Check field edit access for submitted attributes.
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $entity,
                    (string) $fieldName,
                    'edit',
                    $this->account,
                );
                if ($fieldResult->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$fieldName}'."),
                    );
                }
            }

            // CW-v1 WP-0 (docs/specs/content-workflow.md): an entity constructor
            // may default `status` to published (Node does), but an account
            // forbidden from editing `status` must not create born-published
            // content. Applies only when the client did not supply `status` (a
            // supplied value was already access-checked above).
            if ($entity instanceof FieldableInterface
                && !\array_key_exists('status', $attributes)
                && $this->accessHandler->checkFieldAccess($entity, 'status', 'edit', $this->account)->isForbidden()) {
                $entity->set('status', 0);
            }
        }

        try {
            $repository->save($entity);
        } catch (UniqueConstraintViolationException) {
            return $this->errorDocument(
                new JsonApiError(
                    '409',
                    'Conflict',
                    sprintf("An entity of type '%s' with this ID already exists.", $entityTypeId),
                ),
            );
        } catch (EntityValidationException $e) {
            return $this->validationError($entityTypeId, $e);
        } catch (TransitionDeniedException $e) {
            // WP2 rework (review finding #8): WorkflowStateGuard denies from
            // PRE_SAVE inside save() — never let it surface as an uncaught 500.
            return $this->errorDocument($this->workflowTransitionDeniedError($e));
        }

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

        return new JsonApiDocument(
            data: $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
            meta: ['created' => true],
            statusCode: 201,
        );
    }

    /**
     * PATCH — update an existing entity.
     *
     * @param string               $entityTypeId The entity type.
     * @param int|string           $id           The entity ID.
     * @param array<string, mixed> $data         The full JSON:API request body (expects 'data.type' and optionally 'data.attributes').
     */
    public function update(string $entityTypeId, int|string $id, array $data): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        $entity = $this->loadByIdOrUuid($entityTypeId, $id);

        if ($entity === null) {
            return $this->errorDocument(
                JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
            );
        }

        // C-22 WP3: save path now goes through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);

        // Validate request data structure.
        if (!isset($data['data']) || !isset($data['data']['type'])) {
            return $this->errorDocument(
                JsonApiError::badRequest('Missing required "data" object with "type" member.'),
            );
        }

        if ($data['data']['type'] !== $entityTypeId) {
            return $this->errorDocument(
                JsonApiError::unprocessable(
                    "Resource type '{$data['data']['type']}' does not match endpoint type '{$entityTypeId}'.",
                ),
            );
        }

        // Validate data.id matches the entity if provided (JSON:API spec: 409 Conflict).
        if (isset($data['data']['id']) && (string) $data['data']['id'] !== (string) $entity->uuid()) {
            return $this->errorDocument(
                JsonApiError::conflict(
                    "Resource id '{$data['data']['id']}' does not match entity id '{$entity->uuid()}'.",
                ),
            );
        }

        // optimistic-locking-01KTXCHY FR-006: the PATCH body's resource-object
        // meta is the expectation seam (headers do not reach this controller —
        // research D4; If-Match is explicitly NOT this contract).
        $expectedRevisionId = null;
        $meta = $data['data']['meta'] ?? null;
        if (is_array($meta) && array_key_exists('expected_revision_id', $meta)) {
            $candidate = $meta['expected_revision_id'];
            if (!is_int($candidate) || $candidate < 1) {
                return $this->errorDocument(
                    JsonApiError::badRequest('data.meta.expected_revision_id must be a positive integer.'),
                );
            }
            // Friendly screen for types the storage layer would reject anyway
            // (single-axis revisionable only); the storage \LogicException
            // remains the invariant backstop in saveWithExpectation().
            $definition = $this->entityTypeManager->getDefinition($entityTypeId);
            if (!$definition->isRevisionable() || $definition->isTranslatable()) {
                return $this->errorDocument(
                    JsonApiError::unprocessable(
                        "Entity type '{$entityTypeId}' does not support revision expectations.",
                    ),
                );
            }
            $expectedRevisionId = $candidate;
        }

        // Check update access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $access = $this->accessHandler->check($entity, 'update', $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for updating entity '{$id}'."),
                );
            }
        }

        // CW-v1 option-1 (#1920 PR-3, design §4): the PATCH TARGET becomes
        // the WORKING COPY — `loadWorkingCopy()` returns the tip revision
        // when the entity is disciplined and a draft exists, else it is
        // exactly `$entity` above (mechanically safe for every undisciplined
        // entity — pinned by a regression test). The 404 shape and the
        // entity/field-access GATES above and below intentionally still
        // evaluate `$entity` (the `find()`-loaded, view/update-gated
        // instance) — access decisions are type/bundle-scoped, not
        // revision-scoped, so this is no behavior change (PR-3 report
        // judgment note). `$target` is what receives the attribute writes
        // and what gets saved/serialized. `$repository` was already resolved
        // above (C-22 WP3).
        $target = $repository->loadWorkingCopy((string) $entity->id()) ?? $entity;

        // CW-v1 option-1 design §5 (findings #1/#2), rework: echo-tolerant
        // rejection (Drupal JSON:API parity). A payload key that is neither a
        // declared field nor a writable entity key is reject-not-strip as
        // before (hard 422). An identity/bookkeeping column (revision_id,
        // published_revision_id, uuid, ...) is refused ONLY when its
        // submitted value DIFFERS from the entity's current stored value — a
        // pure echo of a value the client read via GET/serialize (FR-008:
        // `revision_id` is a documented load-bearing READ attribute) passes,
        // because a read-modify-write client (the admin SPA's
        // `SchemaForm.vue`) submits the FULL loaded attribute object on every
        // save. Runs BEFORE the per-field access loop and BEFORE any
        // set()/save() — nothing is applied on refusal. Unconditional (not
        // gated on an access handler/account being wired): this is a
        // structural validation, not an access decision, mirroring
        // validateQueryFields() on the read path.
        //
        // CW-v1 option-1 PR-3 judgment note: the comparison authority below reads
        // `$target` (the WORKING COPY), not
        // `$entity`'s (the published pointer's). A client that GETs the
        // working copy (`?workingCopy=1`) and echoes ITS `revision_id` back
        // on PATCH is echoing the TIP's revision id, which differs from the
        // published pointer's `revision_id` whenever a draft is in flight —
        // comparing against `$entity` would misclassify that
        // legitimate echo as a differing (refused) value. Comparing against
        // the actual PATCH target's own stored values is the only basis that
        // makes the echo tolerance meaningful once the target and the
        // find()-loaded gate entity can diverge. Pinned by test (undisciplined
        // entities: `$target === $entity` in content, so this is a no-op there).
        $attributes = $data['data']['attributes'] ?? [];
        $guardResult = EntityWritePayloadGuard::evaluateEntityForUpdate(
            $this->entityTypeManager->getDefinition($entityTypeId),
            $target->bundle(),
            $attributes,
            $this->entityTypeManager,
            $target,
        );
        if ($guardResult->refusedKeys !== []) {
            return $this->errorDocument($this->writeAllowlistError($guardResult->refusedKeys));
        }
        // Belt: an allowed echo must never reach the field-access loop or
        // $target->set() — strip it from the working payload before either
        // runs, so a stale in-memory pointer read before a concurrent
        // transition can never be written back over the real current value.
        $attributes = self::stripEchoedKeys($attributes, $guardResult);

        // Check field edit access for submitted attributes. Evaluated
        // against $entity (type/bundle-scoped — see the judgment note
        // above), not the working-copy $target.
        if ($this->accessHandler !== null && $this->account !== null) {
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $entity,
                    (string) $fieldName,
                    'edit',
                    $this->account,
                );
                if ($fieldResult->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$fieldName}'."),
                    );
                }
            }
        }

        // Apply attribute updates to the WORKING COPY.
        if (!$target instanceof FieldableInterface && !$target instanceof ConfigEntityInterface) {
            return $this->errorDocument(
                JsonApiError::unprocessable("Entity type '{$entityTypeId}' does not support field updates."),
            );
        }
        foreach ($attributes as $field => $value) {
            $target->set($field, $value);
        }

        if ($expectedRevisionId !== null) {
            $failure = $this->saveWithExpectation($entityTypeId, $target, $expectedRevisionId);
            if ($failure !== null) {
                return $failure;
            }
        } else {
            try {
                $repository->save($target);
            } catch (UniqueConstraintViolationException) {
                // Mirrors create()'s 409 mapping (WP2 review): a PATCH that
                // trips a uniqueness constraint (e.g. the attachment
                // one-active-per-parent partial index under a race) is a
                // caller-visible Conflict, never a raw 500 with driver SQL
                // in the body. Names the REAL entity id, not the request
                // locator (contract §15 locator honesty).
                return $this->errorDocument($this->uniquenessConflictError($entityTypeId, (string) $target->id()));
            } catch (EntityValidationException $e) {
                return $this->validationError($entityTypeId, $e);
            } catch (TransitionDeniedException $e) {
                // WP2 rework (review finding #8): same PRE_SAVE guard denial
                // as create() and the expectation-stated PATCH path below.
                return $this->errorDocument($this->workflowTransitionDeniedError($e));
            }
        }

        $resource = $this->serializer->serialize($target, $this->accessHandler, $this->account);

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
        );
    }

    /**
     * Persist an expectation-stated PATCH through the revision-aware
     * repository pipeline (optimistic-locking-01KTXCHY, contract
     * conflict-surfaces.md §11 — a revision is cut and the repository
     * lifecycle events fire; the no-expectation path is untouched).
     *
     * Conflict payloads name the REAL entity id ({@see RevisionConflictException::$entityId}),
     * not the request locator, so uuid-routed PATCHes stay honest (contract §15).
     *
     * @return ?JsonApiDocument An error document on conflict / validation /
     *                          unsupported expectation; null when the save succeeded.
     */
    private function saveWithExpectation(
        string $entityTypeId,
        EntityInterface $entity,
        int $expectedRevisionId,
    ): ?JsonApiDocument {
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        if (!$repository instanceof EntityRepository) {
            // Only the concrete EntityRepository carries a SaveContext: a
            // stated expectation against any other implementation is refused,
            // never silently saved plain (FR-007 at the surface).
            return $this->errorDocument(
                JsonApiError::unprocessable(
                    "Entity type '{$entityTypeId}' does not support revision expectations.",
                ),
            );
        }

        try {
            $repository->save($entity, context: SaveContext::default()->withExpectedRevisionId($expectedRevisionId));
        } catch (UniqueConstraintViolationException) {
            // Same 409 mapping as the no-expectation PATCH path and
            // create() (WP2 review): the expectation can pass and the base
            // write still trip a uniqueness constraint — never a raw 500.
            return $this->errorDocument($this->uniquenessConflictError($entityTypeId, (string) $entity->id()));
        } catch (RevisionConflictException $e) {
            return $this->errorDocument(JsonApiError::conflict(
                "Entity of type '{$entityTypeId}' with ID '{$e->entityId}' was modified: "
                    . "expected revision {$e->expectedRevisionId}, current revision is "
                    . ($e->currentRevisionId === null ? 'none' : (string) $e->currentRevisionId) . '.',
                code: 'REVISION_CONFLICT',
                meta: [
                    'expected_revision_id' => $e->expectedRevisionId,
                    'current_revision_id' => $e->currentRevisionId,
                ],
            ));
        } catch (EntityValidationException $e) {
            return $this->validationError($entityTypeId, $e);
        } catch (TransitionDeniedException $e) {
            // WP2 rework (review finding #8): same PRE_SAVE guard denial as
            // create() and the plain PATCH path above.
            return $this->errorDocument($this->workflowTransitionDeniedError($e));
        } catch (\LogicException $e) {
            // The storage rejection matrix is the invariant backstop: a stated
            // expectation the pipeline cannot honor is a 4xx caller error,
            // never a 500 (contract §10).
            return $this->errorDocument(JsonApiError::unprocessable($e->getMessage()));
        }

        return null;
    }

    private function validationError(string $entityTypeId, EntityValidationException $exception): JsonApiDocument
    {
        return $this->errorDocument(JsonApiError::unprocessable(
            "Validation failed for entity of type '{$entityTypeId}': {$exception->getMessage()}",
        ));
    }

    /**
     * The 409 body for a uniqueness-constraint trip during PATCH — same
     * status/title shape as create()'s duplicate-ID 409 (codeless, so the
     * `code` member keeps discriminating the REVISION_CONFLICT 409).
     */
    private function uniquenessConflictError(string $entityTypeId, string $entityId): JsonApiError
    {
        return JsonApiError::conflict(
            sprintf("Updating entity of type '%s' with ID '%s' violated a uniqueness constraint.", $entityTypeId, $entityId),
        );
    }

    /**
     * Remove every allowed-echo key ({@see EntityWritePayloadGuardResult::$echoedKeys})
     * from a working attributes payload before it reaches the field-access
     * loop or the apply loop (PR-4 rework "belt": even an allowed echo of an
     * identity/bookkeeping column must never be applied via `$entity->set()`).
     *
     * @param array<int|string, mixed> $attributes
     * @return array<int|string, mixed>
     */
    private static function stripEchoedKeys(array $attributes, EntityWritePayloadGuardResult $guardResult): array
    {
        foreach ($guardResult->echoedKeys as $echoedKey) {
            unset($attributes[$echoedKey]);
        }

        return $attributes;
    }

    /**
     * The 422 body for a write-side field-allowlist refusal
     * ({@see EntityWritePayloadGuard}, CW-v1 option-1 design §5, findings
     * #1/#2): a payload key is neither a declared field nor a writable
     * entity key, or is an identity/bookkeeping column (`revision_id`,
     * `published_revision_id`, ...). Names every refused key so the caller
     * can see exactly what was rejected — reject, never strip (design
     * invariant 5, Drupal JSON:API parity).
     *
     * @param list<string> $refusedKeys
     */
    private function writeAllowlistError(array $refusedKeys): JsonApiError
    {
        return JsonApiError::unprocessable(
            sprintf('The following attribute(s) are not writable: %s.', implode(', ', $refusedKeys)),
            code: 'FIELD_NOT_WRITABLE',
            meta: ['refused_keys' => $refusedKeys],
        );
    }

    /**
     * Map a {@see TransitionDeniedException} thrown from PRE_SAVE by
     * WorkflowStateGuard (WP2 rework, review finding #8) to a JSON:API error
     * document — never an uncaught 500. `REASON_PERMISSION` is a caller-access
     * problem (403 Forbidden); every other reason (`illegal_edge`,
     * `unknown_transition`, `unbound`) is a caller-request problem (422
     * Unprocessable Entity). The `WORKFLOW_TRANSITION_DENIED` code plus
     * `reason` meta is the machine-readable discriminator (mirrors
     * REVISION_CONFLICT's code/meta pattern). The exception message is
     * already operator-friendly, so it passes through as the detail.
     */
    private function workflowTransitionDeniedError(TransitionDeniedException $e): JsonApiError
    {
        $meta = ['reason' => $e->reason];

        return $e->reason === TransitionDeniedException::REASON_PERMISSION
            ? JsonApiError::forbidden($e->getMessage(), code: 'WORKFLOW_TRANSITION_DENIED', meta: $meta)
            : JsonApiError::unprocessable($e->getMessage(), code: 'WORKFLOW_TRANSITION_DENIED', meta: $meta);
    }

    /**
     * DELETE — delete an entity.
     *
     * @param string     $entityTypeId The entity type.
     * @param int|string $id           The entity ID.
     */
    public function destroy(string $entityTypeId, int|string $id): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        $entity = $this->loadByIdOrUuid($entityTypeId, $id);

        if ($entity === null) {
            return $this->errorDocument(
                JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
            );
        }

        // Check delete access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $access = $this->accessHandler->check($entity, 'delete', $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for deleting entity '{$id}'."),
                );
            }
        }

        // C-22 WP3: delete path now goes through the canonical repository.
        $this->entityTypeManager->getRepository($entityTypeId)->delete($entity);

        return JsonApiDocument::empty(meta: ['deleted' => true], statusCode: 204);
    }

    /**
     * Load an entity by primary key or UUID.
     *
     * The JSON:API serializer exposes UUID as the resource ID, so incoming
     * requests may contain either the numeric primary key or a UUID string.
     */
    private function loadByIdOrUuid(string $entityTypeId, int|string $id): ?\Waaseyaa\Entity\EntityInterface
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        // If the entity type has a uuid key and the ID looks like a UUID, query by uuid.
        // This is identity resolution only: query access is a VIEW filter, while
        // update/delete callers authorize the resolved entity for their own
        // operation. Numeric find() is likewise unfiltered. Every caller applies
        // its operation-specific access check after this method returns.
        if (isset($keys['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id)) {
            $query = $repository->getQuery()
                ->accessCheck(false)
                ->condition($keys['uuid'], (string) $id);
            $ids = $query->execute();
            if ($ids === []) {
                return null;
            }
            return $repository->find((string) reset($ids));
        }

        return $repository->find((string) $id);
    }

    /**
     * Validate that a collection query only filters/sorts on allowlisted field names.
     *
     * A field is allowed when it is either a declared field (a key of
     * {@see EntityTypeManagerInterface::resolveFieldDefinitions()}) or one of the entity
     * type's structural keys ({@see \Waaseyaa\Entity\EntityTypeInterface::getKeys()} —
     * id/uuid/label/bundle/langcode/revision/...). Every other field name is REJECTED with a
     * 400, even if it would otherwise resolve to a harmless no-op `_data` lookup: an
     * unvalidated field name is what let an anonymous request reach
     * {@see \Waaseyaa\EntityStorage\SqlEntityQuery}'s raw `json_extract('$.<field>')`
     * interpolation (audit R2 WP1 — anonymous SQL injection via filter/sort field name). This
     * is an allowlist, not a denylist: previously only {@see self::ALWAYS_INTERNAL_FIELDS} and
     * `settings['internal'] => true` fields were rejected, which let any other undeclared
     * `_data` key (and any SQL metacharacter payload disguised as one) through untouched.
     *
     * A field that passes the allowlist is still rejected when it is in
     * {@see self::ALWAYS_INTERNAL_FIELDS} (credential keys, mirrored even for a legitimately
     * declared field) or when its FieldDefinition sets `settings['internal'] => true` — a
     * declared field can still be a secret the caller must not use as a filter/sort oracle.
     *
     * Returns an error document to short-circuit `index()`, or null when every filter/sort
     * field is permitted.
     */
    private function validateQueryFields(ParsedQuery $parsedQuery, string $entityTypeId): ?JsonApiDocument
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

        foreach ($parsedQuery->filters as $filter) {
            if ($isRejected($filter->field)) {
                return $this->errorDocument(JsonApiError::badRequest("Cannot filter by field '{$filter->field}'."));
            }
        }

        foreach ($parsedQuery->sorts as $sort) {
            if ($isRejected($sort->field)) {
                return $this->errorDocument(JsonApiError::badRequest("Cannot sort by field '{$sort->field}'."));
            }
        }

        return null;
    }

    /**
     * Create an error document from a single error.
     */
    private function errorDocument(JsonApiError $error): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([$error], statusCode: (int) $error->status);
    }

    /**
     * Canonical single-read 404. Used for BOTH a nonexistent id and a
     * view-denied entity — byte-identical on purpose (FR-003 / NFR-002,
     * mission request-surface-hardening-01KTX7F2). Do not fork the message.
     */
    private function notFoundDocument(string $entityTypeId, int|string $id): JsonApiDocument
    {
        return $this->errorDocument(
            JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
        );
    }

    /**
     * Convert a label to a machine name (lowercase, underscores only).
     *
     * Mirrors packages/admin/app/components/MachineNameInput.vue frontend
     * logic. If either implementation changes, the other must be updated.
     */
    private static function toMachineName(string $value): string
    {
        $machine = strtolower($value);
        $machine = preg_replace('/[^a-z0-9]+/', '_', $machine) ?? $machine;
        $machine = trim($machine, '_');

        return substr($machine, 0, 128);
    }
}
