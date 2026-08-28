<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Http\EntityMutationPrecondition;
use Waaseyaa\Api\Query\PaginationLinks;
use Waaseyaa\Api\Query\ParsedQuery;
use Waaseyaa\Api\Query\QueryApplier;
use Waaseyaa\Api\Query\QueryParser;
use Waaseyaa\Api\Sanitizer\RichTextSanitizer;
use Waaseyaa\Entity\Concurrency\EntityMutationConflictException;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\ConfigEntityInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Write\EntityWritePayloadGuard;
use Waaseyaa\Entity\Write\EntityWritePayloadGuardResult;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\BundleUniqueKeyConflictException;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;
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
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'legacy_pass', 'password', 'password_hash'];

    /**
     * The sanitized projection every consumer has always received, and the
     * value of `meta.representation` when nothing is opted into (#2552).
     */
    private const REPRESENTATION_RENDERED = 'rendered';

    /**
     * The lossless editor projection (#2552): HTML-bearing attributes are the
     * stored value byte-for-byte. Opt-in only, and only alongside
     * `?workingCopy=1` so it inherits that branch's entity-`update` gate. Each
     * outgoing HTML attribute must also pass the field-`edit` gate PATCH uses.
     */
    private const REPRESENTATION_EDITING = 'editing';

    /** @var list<string> */
    private const REPRESENTATIONS = [self::REPRESENTATION_RENDERED, self::REPRESENTATION_EDITING];

    private readonly InternalFieldVisibilityPolicy $internalFieldVisibility;

    private readonly EntityIdentifierResolver $identifierResolver;

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
        ?InternalFieldVisibilityPolicy $internalFieldVisibility = null,
    ) {
        $this->internalFieldVisibility = $internalFieldVisibility ?? new InternalFieldVisibilityPolicy();
        $this->identifierResolver = new EntityIdentifierResolver($entityTypeManager);
    }

    /**
     * GET collection — list entities of a given type.
     *
     * @param string               $entityTypeId The entity type to list.
     * @param array<string, mixed> $query        Optional query parameters (filter, sort, page, fields).
     */
    public function index(string $entityTypeId, array $query = []): JsonApiDocument
    {
        $exposureError = $this->entityTypeExposureError($entityTypeId);
        if ($exposureError !== null) {
            return $exposureError;
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

        // #2552: a collection read has no per-entity `update` gate to hang the
        // lossless editor projection on, so it can only ever serve `rendered`.
        // Refuse `?representation=editing` LOUDLY rather than silently serving
        // the sanitized projection under a name that promises stored bytes --
        // a silent downgrade is exactly the defect #2552 reports one layer up
        // (the client believes it holds stored bytes and PATCHes stripped ones
        // back).
        $representationError = $this->representationError($query, allowEditing: false);
        if ($representationError !== null) {
            return $representationError;
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
            // pages, not the size of the current page. The storage query already
            // applies deny-by-default entity access; this second pass is still
            // required because the collection contract also excludes entities
            // whose filter/sort fields the account may not read (R14).
            // Recompute the true total without pagination using both gates.
            $total = $this->accessFilteredTotal($repository, $parsedQuery, $gatedQueryFields);
        }

        $resources = [];
        foreach ($entities as $entity) {
            $resources[] = $this->serializer->serialize(
                $entity,
                $this->accessHandler,
                $this->account,
                includeMutationToken: $this->canMutate($entity),
            );
        }

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
     * the data the consumer receives over successive pages — never merely the
     * page size or a count that omits the R14 field-read gate. Filters only:
     * sorts and pagination are intentionally omitted.
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
     *                                            fieldsets, CW-v1 option-1's `workingCopy`, and
     *                                            #2552's `representation` — see
     *                                            {@see representationError()}).
     */
    public function show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument
    {
        $exposureError = $this->entityTypeExposureError($entityTypeId);
        if ($exposureError !== null) {
            return $exposureError;
        }

        $parsedQuery = new QueryParser()->parse($query);
        $queryFieldError = $this->validateQueryFields($parsedQuery, $entityTypeId);
        if ($queryFieldError !== null) {
            return $queryFieldError;
        }

        // #2552: structural validation of `?representation=`, deliberately
        // BEFORE the entity is loaded. Its outcome depends only on the query
        // string, never on whether the entity exists or is visible, so it
        // cannot become an existence oracle the way a post-load 400 would.
        $representationError = $this->representationError($query, allowEditing: true);
        if ($representationError !== null) {
            return $representationError;
        }
        $editingRepresentation = $this->editingRepresentationRequested($query);

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
        $accessEntity = $entity;
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

        $canMutate = $this->canMutate($entity);
        // $editingRepresentation is reachable ONLY through the working-copy
        // branch above, which has already required entity `update` access (or
        // returned 403) — see representationError()'s pairing rule. The
        // per-field edit gate below completes that authorization decision for
        // every outgoing HTML attribute before any stored byte is serialized.
        $resource = $this->serializer->serialize(
            $entity,
            $this->accessHandler,
            $this->account,
            includeMutationToken: $canMutate,
        );

        // Apply sparse fieldsets per JSON:API spec (attributes and relationships).
        if (isset($parsedQuery->sparseFieldsets[$entityTypeId])) {
            $allowedFields = $parsedQuery->sparseFieldsets[$entityTypeId];
            $resource = SparseFieldsetApplicator::apply($resource, $allowedFields);
        }

        if ($editingRepresentation) {
            // The rendered resource above is the canonical visibility
            // projection: internal, unexposed and field-view-forbidden
            // attributes are already absent, and the sparse fieldset has
            // already narrowed it to what this request would receive. Before
            // replacing that safe projection with stored HTML, require the
            // same per-field edit authority PATCH requires. Entity update
            // access alone does not prove authority to rewrite every field.
            if ($this->losslessHtmlFieldEditDenied($accessEntity, $entity, $resource)) {
                return $this->errorDocument(JsonApiError::forbidden(
                    "Access denied for the editing representation of entity '{$id}'.",
                ));
            }

            $resource = $this->serializer->serialize(
                $entity,
                $this->accessHandler,
                $this->account,
                includeMutationToken: $canMutate,
                losslessHtml: true,
            );
            if (isset($parsedQuery->sparseFieldsets[$entityTypeId])) {
                $resource = SparseFieldsetApplicator::apply($resource, $parsedQuery->sparseFieldsets[$entityTypeId]);
            }
        }

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
            // Stated on EVERY single-entity read, not just the opted-in one,
            // so a consumer can always tell which projection it is holding
            // without inferring it from the request it thinks it made.
            meta: ['representation' => $editingRepresentation ? self::REPRESENTATION_EDITING : self::REPRESENTATION_RENDERED],
            headers: $canMutate ? $this->mutationHeaders($entity) : [],
        );
    }

    /**
     * Structural validation of `?representation=` (#2552).
     *
     * `rendered` (the default) is the sanitized projection every consumer has
     * always received. `editing` is the LOSSLESS editor projection: for any
     * field whose type is in {@see \Waaseyaa\Api\Sanitizer\RichTextSanitizer::HTML_FIELD_TYPES} the
     * served attribute is the stored value byte-for-byte, so a GET →
     * modify → PATCH round trip cannot silently rewrite markup the sanitizer
     * would otherwise normalize away (`class` hooks, `data-*`, `style`,
     * inline SVG, `<table>` structure).
     *
     * Two rules, both loud:
     *   1. An unrecognized value is a 400, never a fallback to `rendered`.
     *   2. `editing` REQUIRES `?workingCopy=1`. That pairing is what binds the
     *      lossless projection to the existing entity-`update` gate in
     *      {@see show()} — without it the flag would have no authorization
     *      anchor at all. Requesting `editing` alone is a 400; requesting it
     *      with `workingCopy` but without update access is the working-copy
     *      403. Neither ever degrades silently to the sanitized value, because
     *      a client that believes it holds stored bytes and holds stripped
     *      ones is precisely the destructive round trip #2552 reports.
     *
     * @param array<string, mixed> $query
     */
    private function representationError(array $query, bool $allowEditing): ?JsonApiDocument
    {
        $value = $query['representation'] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, self::REPRESENTATIONS, true)) {
            // The submitted value is NOT echoed back: it is unvalidated caller
            // input, and the supported set is short enough to state outright.
            return $this->errorDocument(JsonApiError::badRequest(
                "Unsupported 'representation'. Supported values: '"
                . self::REPRESENTATION_RENDERED . "' (default), '" . self::REPRESENTATION_EDITING . "'.",
            ));
        }

        if ($value !== self::REPRESENTATION_EDITING) {
            return null;
        }

        if (!$allowEditing) {
            return $this->errorDocument(JsonApiError::badRequest(
                "The '" . self::REPRESENTATION_EDITING . "' representation is available only on a single-entity "
                . 'read with ?workingCopy=1, not on a collection.',
            ));
        }

        if (!$this->workingCopyRequested($query)) {
            return $this->errorDocument(JsonApiError::badRequest(
                "The '" . self::REPRESENTATION_EDITING . "' representation requires ?workingCopy=1.",
            ));
        }

        return null;
    }

    /**
     * True when the caller opted into the lossless editor projection. Only
     * meaningful after {@see representationError()} has passed, which is what
     * guarantees the value is a known string AND paired with `?workingCopy=1`.
     *
     * @param array<string, mixed> $query
     */
    private function editingRepresentationRequested(array $query): bool
    {
        return ($query['representation'] ?? null) === self::REPRESENTATION_EDITING;
    }

    /**
     * Whether the requested editing projection contains an HTML field the
     * caller may view but may not edit.
     *
     * The access decision intentionally uses the find()-loaded entity, exactly
     * as update() does, while field definitions come from the working copy that
     * will be served. The rendered resource supplies the effective outgoing
     * field set after internal, field-view and sparse-fieldset projection.
     */
    private function losslessHtmlFieldEditDenied(
        EntityInterface $accessEntity,
        EntityInterface $servedEntity,
        JsonApiResource $renderedResource,
    ): bool {
        if ($this->accessHandler === null || $this->account === null) {
            return true;
        }

        $definitions = $this->entityTypeManager->resolveFieldDefinitions(
            $servedEntity->getEntityTypeId(),
            $servedEntity->bundle(),
        );
        foreach (array_keys($renderedResource->attributes) as $fieldName) {
            $definition = $definitions[$fieldName] ?? null;
            if ($definition === null
                || !RichTextSanitizer::isHtmlFieldType($definition->getType())
            ) {
                continue;
            }

            if ($this->accessHandler->checkFieldAccess(
                $accessEntity,
                $fieldName,
                'edit',
                $this->account,
            )->isForbidden()) {
                return true;
            }
        }

        return false;
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
        $exposureError = $this->entityTypeExposureError($entityTypeId);
        if ($exposureError !== null) {
            return $exposureError;
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

        $advisoryContext = $this->advisorySaveContext($data['data']['meta'] ?? null);
        if ($advisoryContext instanceof JsonApiDocument) {
            return $advisoryContext;
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
            if ($repository instanceof EntityRepository) {
                $repository->save($entity, context: $advisoryContext);
            } elseif ($advisoryContext->saveAdvisoryAcknowledgements() === []) {
                $repository->save($entity);
            } else {
                return $this->errorDocument(JsonApiError::unprocessable(
                    "Entity type '{$entityTypeId}' does not support save advisory acknowledgements.",
                ));
            }
        } catch (BundleUniqueKeyConflictException $e) {
            return $this->errorDocument($this->bundleUniqueKeyConflictError($e));
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
        } catch (SaveAdvisoryAcknowledgementRequiredException $e) {
            return $this->saveAdvisoryError($e);
        } catch (TransitionDeniedException $e) {
            // WP2 rework (review finding #8): WorkflowStateGuard denies from
            // PRE_SAVE inside save() — never let it surface as an uncaught 500.
            return $this->errorDocument($this->workflowTransitionDeniedError($e));
        }

        $resource = $this->serializer->serialize(
            $entity,
            $this->accessHandler,
            $this->account,
            includeMutationToken: true,
        );

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
    public function update(
        string $entityTypeId,
        int|string $id,
        array $data,
        ?EntityMutationToken $expectedMutation = null,
    ): JsonApiDocument {
        $exposureError = $this->entityTypeExposureError($entityTypeId);
        if ($exposureError !== null) {
            return $exposureError;
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
        $advisoryContext = $this->advisorySaveContext($meta);
        if ($advisoryContext instanceof JsonApiDocument) {
            return $advisoryContext;
        }
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
        if ($expectedMutation !== null) {
            if (!$target instanceof EntityBase) {
                return $this->errorDocument(JsonApiError::unprocessable(
                    "Entity type '{$entityTypeId}' cannot carry a mutation precondition.",
                ));
            }
            $target->_hydrateMutationToken($expectedMutation);
        }

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
            $failure = $this->saveWithExpectation(
                $entityTypeId,
                $target,
                $expectedRevisionId,
                $advisoryContext,
            );
            if ($failure !== null) {
                return $failure;
            }
        } else {
            try {
                if ($repository instanceof EntityRepository) {
                    $repository->save($target, context: $advisoryContext);
                } elseif ($advisoryContext->saveAdvisoryAcknowledgements() === []) {
                    $repository->save($target);
                } else {
                    return $this->errorDocument(JsonApiError::unprocessable(
                        "Entity type '{$entityTypeId}' does not support save advisory acknowledgements.",
                    ));
                }
            } catch (EntityMutationConflictException) {
                return $this->mutationConflictDocument();
            } catch (BundleUniqueKeyConflictException $e) {
                return $this->errorDocument($this->bundleUniqueKeyConflictError($e));
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
            } catch (SaveAdvisoryAcknowledgementRequiredException $e) {
                return $this->saveAdvisoryError($e);
            } catch (TransitionDeniedException $e) {
                // WP2 rework (review finding #8): same PRE_SAVE guard denial
                // as create() and the expectation-stated PATCH path below.
                return $this->errorDocument($this->workflowTransitionDeniedError($e));
            }
        }

        $resource = $this->serializer->serialize(
            $target,
            $this->accessHandler,
            $this->account,
            includeMutationToken: true,
        );

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
            headers: $this->mutationHeaders($target),
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
        SaveContext $context,
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
            $repository->save($entity, context: $context->withExpectedRevisionId($expectedRevisionId));
        } catch (BundleUniqueKeyConflictException $e) {
            return $this->errorDocument($this->bundleUniqueKeyConflictError($e));
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
        } catch (SaveAdvisoryAcknowledgementRequiredException $e) {
            return $this->saveAdvisoryError($e);
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

    private function advisorySaveContext(mixed $meta): SaveContext|JsonApiDocument
    {
        if ($meta === null) {
            return SaveContext::default();
        }
        if (!is_array($meta)) {
            return $this->errorDocument(JsonApiError::badRequest('data.meta must be an object.'));
        }

        $member = 'save_advisory_acknowledgements';
        if (!array_key_exists($member, $meta)) {
            return SaveContext::default();
        }
        $tokens = $meta[$member];
        if (!is_array($tokens)) {
            return $this->errorDocument(JsonApiError::badRequest(
                "data.meta.{$member} must be a list of lowercase 64-character hex tokens.",
            ));
        }

        try {
            return SaveContext::default()->withSaveAdvisoryAcknowledgements($tokens);
        } catch (\InvalidArgumentException) {
            return $this->errorDocument(JsonApiError::badRequest(
                "data.meta.{$member} must be a list of at most 32 lowercase 64-character hex tokens.",
            ));
        }
    }

    private function saveAdvisoryError(
        SaveAdvisoryAcknowledgementRequiredException $exception,
    ): JsonApiDocument {
        return $this->errorDocument(new JsonApiError(
            status: '428',
            title: 'Precondition Required',
            detail: $exception->getMessage(),
            code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            meta: ['save_advisories' => $exception->advisoryPayloads()],
        ));
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

    private function bundleUniqueKeyConflictError(BundleUniqueKeyConflictException $exception): JsonApiError
    {
        return JsonApiError::conflict(
            $exception->getMessage(),
            code: $exception->errorCode,
            meta: [
                'bundle' => $exception->bundle,
                'key' => $exception->keyName,
                'fields' => $exception->fields,
                'values' => $exception->values,
            ],
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
    public function destroy(
        string $entityTypeId,
        int|string $id,
        ?EntityMutationToken $expectedMutation = null,
    ): JsonApiDocument {
        $exposureError = $this->entityTypeExposureError($entityTypeId);
        if ($exposureError !== null) {
            return $exposureError;
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

        if ($expectedMutation !== null) {
            if (!$entity instanceof EntityBase) {
                return $this->errorDocument(JsonApiError::unprocessable(
                    "Entity type '{$entityTypeId}' cannot carry a mutation precondition.",
                ));
            }
            $entity->_hydrateMutationToken($expectedMutation);
        }

        // C-22 WP3: delete path now goes through the canonical repository.
        try {
            $this->entityTypeManager->getRepository($entityTypeId)->delete($entity);
        } catch (EntityMutationConflictException) {
            return $this->mutationConflictDocument();
        }

        return JsonApiDocument::empty(meta: ['deleted' => true], statusCode: 204);
    }

    private function mutationConflictDocument(): JsonApiDocument
    {
        return EntityMutationPrecondition::failedDocument();
    }

    /** @return array<string, string> */
    private function mutationHeaders(EntityInterface $entity): array
    {
        if (!$entity instanceof EntityBase || $entity->mutationToken() === null) {
            return [];
        }

        return ['ETag' => $entity->mutationToken()->toStrongEtag()];
    }

    private function canMutate(EntityInterface $entity): bool
    {
        if ($this->accessHandler === null || $this->account === null) {
            return true;
        }

        return $this->accessHandler->check($entity, 'update', $this->account)->isAllowed()
            || $this->accessHandler->check($entity, 'delete', $this->account)->isAllowed();
    }

    /**
     * Load an entity by primary key or UUID.
     *
     * The JSON:API serializer exposes UUID as the resource ID, so incoming
     * requests may contain either the numeric primary key or a UUID string.
     */
    private function loadByIdOrUuid(string $entityTypeId, int|string $id): ?\Waaseyaa\Entity\EntityInterface
    {
        // Identity resolution only: query access is a VIEW filter, while
        // update/delete callers authorize the resolved entity for their own
        // operation. Numeric find() is likewise unfiltered. Every caller applies
        // its operation-specific access check after this method returns.
        return $this->identifierResolver->resolve($entityTypeId, $id);
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

        $isRejected = function (string $field) use ($allowedFields, $fieldDefinitions, $entityTypeId): bool {
            if (!isset($allowedFields[$field])) {
                return true;
            }
            if (in_array($field, self::ALWAYS_INTERNAL_FIELDS, true)) {
                return true;
            }
            $definition = $fieldDefinitions[$field] ?? null;

            return $this->internalFieldVisibility->isInternal($entityTypeId, $field, $definition);
        };

        foreach ($parsedQuery->filters as $filter) {
            if (str_contains($filter->field, '.')) {
                return $this->errorDocument(JsonApiError::badRequest('Cannot filter by the requested field path.'));
            }
            if ($isRejected($filter->field)) {
                return $this->errorDocument(JsonApiError::badRequest("Cannot filter by field '{$filter->field}'."));
            }
        }

        foreach ($parsedQuery->sorts as $sort) {
            if (str_contains($sort->field, '.')) {
                return $this->errorDocument(JsonApiError::badRequest('Cannot sort by the requested field path.'));
            }
            if ($isRejected($sort->field)) {
                return $this->errorDocument(JsonApiError::badRequest("Cannot sort by field '{$sort->field}'."));
            }
        }

        foreach ($parsedQuery->includes as $include) {
            if (!$this->isAllowedIncludePath($entityTypeId, $include)) {
                return $this->errorDocument(JsonApiError::badRequest('Cannot include the requested relationship path.'));
            }
        }

        return null;
    }

    private function isAllowedIncludePath(string $sourceTypeId, string $path): bool
    {
        $currentTypeId = $sourceTypeId;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                return false;
            }
            $definitions = $this->entityTypeManager->resolveFieldDefinitions($currentTypeId);
            $definition = $definitions[$segment] ?? null;
            if ($definition === null || $definition->getType() !== 'entity_reference') {
                return false;
            }
            $target = $definition->getSetting('target_entity_type_id')
                ?? $definition->getSetting('target_type');
            if (!is_string($target) || $target === '' || !$this->entityTypeManager->hasDefinition($target)) {
                return false;
            }
            if ($this->exposurePolicy !== null && !$this->exposurePolicy->isExposed($target)) {
                return false;
            }
            $currentTypeId = $target;
        }

        return true;
    }

    private function entityTypeExposureError(string $entityTypeId): ?JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(JsonApiError::notFound("Unknown entity type: {$entityTypeId}."));
        }
        if ($this->exposurePolicy === null || $this->exposurePolicy->isExposed($entityTypeId)) {
            return null;
        }
        return $this->errorDocument(JsonApiError::notFound("Unknown entity type: {$entityTypeId}."));
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
