<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Query\PaginationLinks;
use Waaseyaa\Api\Query\QueryApplier;
use Waaseyaa\Api\Query\QueryParser;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;

/**
 * Handles JSON:API CRUD operations.
 *
 * This is a plain PHP class that receives parsed parameters and returns
 * JsonApiDocument objects. It is not tied to any HTTP framework.
 */
final class JsonApiController
{
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

        $storage = $this->entityTypeManager->getStorage($entityTypeId);

        // Parse query parameters.
        $parser = new QueryParser();
        $parsedQuery = $parser->parse($query);
        $applier = new QueryApplier();

        // Count total matching entities (before pagination).
        $countQuery = $storage->getQuery();
        $countQuery->accessCheck(false);
        // Apply only filters to the count query (not sorts/pagination).
        foreach ($parsedQuery->filters as $filter) {
            $countQuery->condition($filter->field, $filter->value, $filter->operator);
        }
        $countQuery->count();
        $countResult = $countQuery->execute();
        $total = (int) ($countResult[0] ?? 0);

        // Build and execute the main query with filters, sorts, and pagination.
        $entityQuery = $storage->getQuery();
        $entityQuery->accessCheck(false);
        $applier->apply($parsedQuery, $entityQuery);

        $ids = $entityQuery->execute();
        $entities = $ids !== [] ? $storage->loadMultiple($ids) : [];

        // Filter by view access if an access handler is available.
        if ($this->accessHandler !== null && $this->account !== null) {
            $entities = array_filter(
                $entities,
                fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
            );
        }

        $resources = $this->serializer->serializeCollection($entities, $this->accessHandler, $this->account);

        // Apply sparse fieldsets if requested.
        if (isset($parsedQuery->sparseFieldsets[$entityTypeId])) {
            $allowedFields = $parsedQuery->sparseFieldsets[$entityTypeId];
            $resources = array_map(
                static fn(JsonApiResource $resource): JsonApiResource => new JsonApiResource(
                    type: $resource->type,
                    id: $resource->id,
                    attributes: array_intersect_key(
                        $resource->attributes,
                        array_flip($allowedFields),
                    ),
                    relationships: $resource->relationships,
                    links: $resource->links,
                    meta: $resource->meta,
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
     * GET single — retrieve a specific entity.
     *
     * @param string     $entityTypeId The entity type.
     * @param int|string $id           The entity ID.
     */
    public function show(string $entityTypeId, int|string $id): JsonApiDocument
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

        // Check view access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $access = $this->accessHandler->check($entity, 'view', $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for viewing entity '{$id}'."),
                );
            }
        }

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
        );
    }

    /**
     * POST — create a new entity.
     *
     * @param string               $entityTypeId The entity type.
     * @param array<string, mixed> $data         The JSON:API resource data (expects 'attributes' key).
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
        $storage = $this->entityTypeManager->getStorage($entityTypeId);

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
            $tempEntity = $storage->create($attributes);
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $tempEntity,
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

        $entity = $storage->create($attributes);
        $storage->save($entity);

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
     * @param array<string, mixed> $data         The JSON:API resource data (expects 'attributes' key).
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

        $storage = $this->entityTypeManager->getStorage($entityTypeId);

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

        $storage->save($entity);

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
        );
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

        $storage = $this->entityTypeManager->getStorage($entityTypeId);

        // Check delete access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $access = $this->accessHandler->check($entity, 'delete', $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for deleting entity '{$id}'."),
                );
            }
        }

        $storage->delete([$entity]);

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
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        // If the entity type has a uuid key and the ID looks like a UUID, query by uuid.
        if (isset($keys['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id)) {
            $query = $storage->getQuery();
            $query->accessCheck(false);
            $query->condition($keys['uuid'], (string) $id);
            $ids = $query->execute();
            if ($ids === []) {
                return null;
            }
            return $storage->load(reset($ids));
        }

        return $storage->load($id);
    }

    /**
     * Create an error document from a single error.
     */
    private function errorDocument(JsonApiError $error): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([$error], statusCode: (int) $error->status);
    }
}
