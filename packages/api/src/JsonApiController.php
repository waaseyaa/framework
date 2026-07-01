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
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;

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

        // Reject filtering/sorting on internal/credential fields. Without this, an anonymous
        // collection request can filter on a secret field (pass, two_factor_secret, etc.) and
        // use match/no-match as a value-enumeration oracle even though the field is never
        // serialised. We mirror the serializer's internal-field policy rather than impose a
        // full field allowlist, because entities legitimately filter on undeclared _data fields.
        $internalFieldError = $this->rejectInternalQueryFields($parsedQuery, $entityTypeId);
        if ($internalFieldError !== null) {
            return $internalFieldError;
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

        // Filter the current page by view access if an access handler is
        // available. Entity-level access is deny-by-default (isAllowed): a
        // Neutral row is not visible. This mirrors show() (single read).
        if ($this->accessHandler !== null && $this->account !== null) {
            $entities = array_filter(
                $entities,
                fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
            );
            // meta.total must reflect the access-filtered total ACROSS all
            // pages, not the size of the current page. The storage COUNT alone
            // is the wrong source here: the query layer drops only Forbidden
            // rows (open-by-default), whereas the collection contract is
            // deny-by-default (isAllowed), so Neutral rows would inflate it.
            // Recompute the true total by re-running the filter set without
            // pagination and counting rows this account may actually view.
            $total = $this->accessFilteredTotal($repository, $parsedQuery);
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
     */
    private function accessFilteredTotal(
        \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository,
        ParsedQuery $parsedQuery,
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
            if ($this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * GET single — retrieve a specific entity.
     *
     * @param string               $entityTypeId The entity type.
     * @param int|string           $id           The entity ID.
     * @param array<string, mixed> $query        Query parameters (supports 'fields' for sparse fieldsets).
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

        // C-22 WP3: create/save now go through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $entity = $repository->create($attributes);

        // Check create access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $bundle = $attributes['bundle'] ?? $entityTypeId;
            $access = $this->accessHandler->checkCreateAccess($entityTypeId, (string) $bundle, $this->account);
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

        // Check field edit access for submitted attributes.
        $attributes = $data['data']['attributes'] ?? [];
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

        // Apply attribute updates.
        if (!$entity instanceof FieldableInterface) {
            return $this->errorDocument(
                JsonApiError::unprocessable("Entity type '{$entityTypeId}' does not support field updates."),
            );
        }
        foreach ($attributes as $field => $value) {
            $entity->set($field, $value);
        }

        if ($expectedRevisionId !== null) {
            $failure = $this->saveWithExpectation($entityTypeId, $entity, $expectedRevisionId);
            if ($failure !== null) {
                return $failure;
            }
        } else {
            $repository->save($entity);
        }

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

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
            return $this->errorDocument(JsonApiError::unprocessable(
                "Validation failed for entity of type '{$entityTypeId}': {$e->getMessage()}",
            ));
        } catch (\LogicException $e) {
            // The storage rejection matrix is the invariant backstop: a stated
            // expectation the pipeline cannot honor is a 4xx caller error,
            // never a 500 (contract §10).
            return $this->errorDocument(JsonApiError::unprocessable($e->getMessage()));
        }

        return null;
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
        if (isset($keys['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id)) {
            $query = $repository->getQuery();
            if ($this->account !== null) {
                $query->setAccount($this->account);
            } else {
                // system context: controller invoked without an account in scope
                $query->accessCheck(false);
            }
            $query->condition($keys['uuid'], (string) $id);
            $ids = $query->execute();
            if ($ids === []) {
                return null;
            }
            return $repository->find((string) reset($ids));
        }

        return $repository->find((string) $id);
    }

    /**
     * Reject a collection query that filters or sorts on an internal/credential field.
     *
     * A field is rejected when it is in {@see self::ALWAYS_INTERNAL_FIELDS} (credential keys,
     * caught even when stored as an undeclared `_data` key) or its FieldDefinition sets
     * `settings['internal'] => true`. Returns an error document to short-circuit `index()`,
     * or null when every filter/sort field is permitted.
     */
    private function rejectInternalQueryFields(ParsedQuery $parsedQuery, string $entityTypeId): ?JsonApiDocument
    {
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId);

        $isInternal = static function (string $field) use ($fieldDefinitions): bool {
            if (in_array($field, self::ALWAYS_INTERNAL_FIELDS, true)) {
                return true;
            }
            $definition = $fieldDefinitions[$field] ?? null;

            return $definition !== null && $definition->getSetting('internal') === true;
        };

        foreach ($parsedQuery->filters as $filter) {
            if ($isInternal($filter->field)) {
                return $this->errorDocument(JsonApiError::badRequest("Cannot filter by field '{$filter->field}'."));
            }
        }

        foreach ($parsedQuery->sorts as $sort) {
            if ($isInternal($sort->field)) {
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
