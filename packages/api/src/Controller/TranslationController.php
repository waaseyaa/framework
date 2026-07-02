<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Exception\JsonApiDocumentException;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\MutableTranslatableInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\TranslatableInterface;

/**
 * Handles translation CRUD sub-endpoints for entities.
 *
 * Routes:
 *   GET    /api/{entity_type}/{id}/translations              — list translations
 *   GET    /api/{entity_type}/{id}/translations/{langcode}   — get specific translation
 *   POST   /api/{entity_type}/{id}/translations/{langcode}   — create translation
 *   PATCH  /api/{entity_type}/{id}/translations/{langcode}   — update translation
 *   DELETE /api/{entity_type}/{id}/translations/{langcode}   — delete translation
 * @api
 */
final class TranslationController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly ResourceSerializer $serializer,
    ) {}

    /**
     * GET /api/{entity_type}/{id}/translations — list all translations for an entity.
     *
     * @return JsonApiDocument Collection of translation resources.
     */
    public function index(Request $request, string $entityTypeId, int|string $id): JsonApiDocument
    {
        try {
            $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        } catch (JsonApiDocumentException $e) {
            return $e->document;
        }

        $denied = $this->checkAccess($request, $entity, 'view');
        if ($denied !== null) {
            return $denied;
        }

        // Same account checkAccess() just authorized — thread it (with the
        // handler) into serialize() so the per-account field filter runs on the
        // response. checkAccess() already denied a null account, so this is
        // non-null in the happy path; the guard fails closed defensively.
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->forbiddenDocument();
        }

        $languages = $entity->getTranslationLanguages();
        $resources = [];

        foreach ($languages as $langcode) {
            $translation = $entity->getTranslation($langcode);
            $resource = $this->serializer->serialize($translation, $this->accessHandler, $account);
            $resources[] = new JsonApiResource(
                type: $resource->type,
                id: $resource->id,
                attributes: $resource->attributes,
                relationships: $resource->relationships,
                links: ['self' => "/api/{$entityTypeId}/{$resource->id}/translations/{$langcode}"],
                meta: ['langcode' => $langcode],
            );
        }

        return JsonApiDocument::fromCollection(
            $resources,
            links: ['self' => "/api/{$entityTypeId}/{$id}/translations"],
            meta: ['total' => count($resources)],
        );
    }

    /**
     * GET /api/{entity_type}/{id}/translations/{langcode} — get a specific translation.
     */
    public function show(Request $request, string $entityTypeId, int|string $id, string $langcode): JsonApiDocument
    {
        try {
            $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        } catch (JsonApiDocumentException $e) {
            return $e->document;
        }

        $denied = $this->checkAccess($request, $entity, 'view');
        if ($denied !== null) {
            return $denied;
        }

        // Same account checkAccess() just authorized — thread it (with the
        // handler) into serialize() so the per-account field filter runs on the
        // response.
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->forbiddenDocument();
        }

        if (!$entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::notFound("Translation '{$langcode}' not found for entity '{$id}'."),
            );
        }

        $translation = $entity->getTranslation($langcode);
        $resource = $this->serializer->serialize($translation, $this->accessHandler, $account);
        $resource = new JsonApiResource(
            type: $resource->type,
            id: $resource->id,
            attributes: $resource->attributes,
            relationships: $resource->relationships,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}/translations/{$langcode}"],
            meta: ['langcode' => $langcode],
        );

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$id}/translations/{$langcode}"],
        );
    }

    /**
     * POST /api/{entity_type}/{id}/translations/{langcode} — create a new translation.
     *
     * @param array<string, mixed> $data JSON:API resource data with 'attributes' key.
     */
    public function store(Request $request, string $entityTypeId, int|string $id, string $langcode, array $data): JsonApiDocument
    {
        try {
            $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        } catch (JsonApiDocumentException $e) {
            return $e->document;
        }

        $denied = $this->checkAccess($request, $entity, 'create');
        if ($denied !== null) {
            return $denied;
        }

        // Same account checkAccess() just authorized — used both for the
        // field-level edit gate below and for the per-account field filter on
        // the serialized response.
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->forbiddenDocument();
        }

        if ($entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::conflict("Translation '{$langcode}' already exists for entity '{$id}'."),
            );
        }

        $attributes = $data['data']['attributes'] ?? [];

        // Create the translation using the dedicated creation method.
        // getTranslation() retrieves an existing translation; addTranslation()
        // explicitly creates a new one, which is the semantically correct
        // operation here (we already confirmed the translation does not exist).
        if (!$entity instanceof MutableTranslatableInterface) {
            return $this->errorDocument(
                new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Entity type '{$entityTypeId}' does not support creating translations.",
                ),
            );
        }

        $translation = $entity->addTranslation($langcode);
        if ($translation instanceof FieldableInterface) {
            // Field-level edit gate (B-6): reject any submitted field the actor
            // may not edit BEFORE mutating, mirroring JsonApiController. Without
            // it, a caller with create access could set a FieldAccessPolicy-
            // forbidden field (e.g. a privileged field) via the translation path.
            foreach (array_keys($attributes) as $field) {
                if ($this->accessHandler->checkFieldAccess($entity, (string) $field, 'edit', $account)->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$field}'."),
                    );
                }
            }
            foreach ($attributes as $field => $value) {
                $translation->set($field, $value);
            }
        }

        // Save the entity with its new translation (C-22 WP3: canonical repository).
        $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

        $resource = $this->serializer->serialize($translation, $this->accessHandler, $account);
        $resource = new JsonApiResource(
            type: $resource->type,
            id: $resource->id,
            attributes: $resource->attributes,
            relationships: $resource->relationships,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}/translations/{$langcode}"],
            meta: ['langcode' => $langcode],
        );

        return new JsonApiDocument(
            data: $resource,
            links: ['self' => "/api/{$entityTypeId}/{$id}/translations/{$langcode}"],
            meta: ['created' => true],
            statusCode: 201,
        );
    }

    /**
     * PATCH /api/{entity_type}/{id}/translations/{langcode} — update an existing translation.
     *
     * @param array<string, mixed> $data JSON:API resource data with 'attributes' key.
     */
    public function update(Request $request, string $entityTypeId, int|string $id, string $langcode, array $data): JsonApiDocument
    {
        try {
            $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        } catch (JsonApiDocumentException $e) {
            return $e->document;
        }

        $denied = $this->checkAccess($request, $entity, 'update');
        if ($denied !== null) {
            return $denied;
        }

        // Same account checkAccess() just authorized — used both for the
        // field-level edit gate below and for the per-account field filter on
        // the serialized response.
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->forbiddenDocument();
        }

        if (!$entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::notFound("Translation '{$langcode}' not found for entity '{$id}'."),
            );
        }

        $attributes = $data['data']['attributes'] ?? [];

        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableInterface) {
            // Field-level edit gate (B-6): reject any submitted field the actor
            // may not edit BEFORE mutating, mirroring JsonApiController. Without
            // it, a caller with update access could set a FieldAccessPolicy-
            // forbidden field (e.g. a privileged field) via the translation path.
            foreach (array_keys($attributes) as $field) {
                if ($this->accessHandler->checkFieldAccess($entity, (string) $field, 'edit', $account)->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$field}'."),
                    );
                }
            }
            foreach ($attributes as $field => $value) {
                $translation->set($field, $value);
            }
        }

        // C-22 WP3: canonical repository.
        $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

        $resource = $this->serializer->serialize($translation, $this->accessHandler, $account);
        $resource = new JsonApiResource(
            type: $resource->type,
            id: $resource->id,
            attributes: $resource->attributes,
            relationships: $resource->relationships,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}/translations/{$langcode}"],
            meta: ['langcode' => $langcode],
        );

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => "/api/{$entityTypeId}/{$id}/translations/{$langcode}"],
        );
    }

    /**
     * DELETE /api/{entity_type}/{id}/translations/{langcode} — delete a translation.
     */
    public function destroy(Request $request, string $entityTypeId, int|string $id, string $langcode): JsonApiDocument
    {
        try {
            $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        } catch (JsonApiDocumentException $e) {
            return $e->document;
        }

        $denied = $this->checkAccess($request, $entity, 'delete');
        if ($denied !== null) {
            return $denied;
        }

        // Cannot delete the original language.
        if ($entity->language() === $langcode) {
            return $this->errorDocument(
                new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Cannot delete the original language '{$langcode}' of entity '{$id}'.",
                ),
            );
        }

        if (!$entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::notFound("Translation '{$langcode}' not found for entity '{$id}'."),
            );
        }

        // removeTranslation is now part of TranslatableInterface — call it directly.
        $entity->removeTranslation($langcode);
        // C-22 WP3: canonical repository.
        $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

        return JsonApiDocument::empty(
            meta: ['deleted' => true, 'langcode' => $langcode],
            statusCode: 204,
        );
    }

    /**
     * Perform an access check for the given entity and operation.
     *
     * Returns a 403 JsonApiDocument if access is denied, null if allowed.
     * The same response shape is returned whether the entity exists or not
     * (anti-enumeration: the body gives no entity-state information).
     *
     * @param string $operation Ability name: view, create, update, delete.
     */
    private function checkAccess(
        Request $request,
        EntityInterface&TranslatableInterface $entity,
        string $operation,
    ): ?JsonApiDocument {
        /** @var AccountInterface|null $account */
        $account = $request->attributes->get('_account');

        // If no account is set on the request, SessionMiddleware did not run or
        // the session pipeline is misconfigured. Treat as anonymous-denied.
        // The access pipeline handles anonymous via AnonymousUser; if the
        // account is absent entirely, we must deny — do not proceed without one.
        if ($account === null) {
            return $this->forbiddenDocument();
        }

        $result = $this->accessHandler->check($entity, $operation, $account);

        if (!$result->isAllowed()) {
            return $this->forbiddenDocument();
        }

        return null;
    }

    /**
     * Returns a 403 Forbidden JSON:API error document.
     *
     * Does not leak entity existence — the same shape is returned whether the
     * entity exists or the account lacks the required ability.
     */
    private function forbiddenDocument(): JsonApiDocument
    {
        return $this->errorDocument(JsonApiError::forbidden());
    }

    /**
     * Load an entity and validate it supports translations.
     *
     * @throws JsonApiDocumentException When the entity cannot be loaded or is not translatable.
     */
    private function loadTranslatableEntity(string $entityTypeId, int|string $id): EntityInterface&TranslatableInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            throw new JsonApiDocumentException(
                $this->errorDocument(JsonApiError::notFound("Unknown entity type: {$entityTypeId}.")),
            );
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        if (!$entityType->isTranslatable()) {
            throw new JsonApiDocumentException(
                $this->errorDocument(new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Entity type '{$entityTypeId}' does not support translations.",
                )),
            );
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $entity = $this->entityTypeManager->getRepository($entityTypeId)->find((string) $id);

        if ($entity === null) {
            throw new JsonApiDocumentException(
                $this->errorDocument(JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found.")),
            );
        }

        if (!$entity instanceof TranslatableInterface) {
            throw new JsonApiDocumentException(
                $this->errorDocument(new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Entity '{$id}' does not implement TranslatableInterface.",
                )),
            );
        }

        return $entity;
    }

    /**
     * Create an error document from a single error.
     */
    private function errorDocument(JsonApiError $error): JsonApiDocument
    {
        return JsonApiDocument::fromErrors([$error], statusCode: (int) $error->status);
    }
}
