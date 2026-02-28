<?php

declare(strict_types=1);

namespace Aurora\Api\Controller;

use Aurora\Api\JsonApiDocument;
use Aurora\Api\JsonApiError;
use Aurora\Api\JsonApiResource;
use Aurora\Api\ResourceSerializer;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\FieldableInterface;
use Aurora\Entity\TranslatableInterface;

/**
 * Handles translation CRUD sub-endpoints for entities.
 *
 * Routes:
 *   GET    /api/{entity_type}/{id}/translations              — list translations
 *   GET    /api/{entity_type}/{id}/translations/{langcode}   — get specific translation
 *   POST   /api/{entity_type}/{id}/translations/{langcode}   — create translation
 *   PATCH  /api/{entity_type}/{id}/translations/{langcode}   — update translation
 *   DELETE /api/{entity_type}/{id}/translations/{langcode}   — delete translation
 */
final class TranslationController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
    ) {}

    /**
     * GET /api/{entity_type}/{id}/translations — list all translations for an entity.
     *
     * @return JsonApiDocument Collection of translation resources.
     */
    public function index(string $entityTypeId, int|string $id): JsonApiDocument
    {
        $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        if ($entity instanceof JsonApiDocument) {
            return $entity;
        }

        $languages = $entity->getTranslationLanguages();
        $resources = [];

        foreach ($languages as $langcode) {
            $translation = $entity->getTranslation($langcode);
            $resource = $this->serializer->serialize($translation);
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
    public function show(string $entityTypeId, int|string $id, string $langcode): JsonApiDocument
    {
        $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        if ($entity instanceof JsonApiDocument) {
            return $entity;
        }

        if (!$entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::notFound("Translation '{$langcode}' not found for entity '{$id}'."),
            );
        }

        $translation = $entity->getTranslation($langcode);
        $resource = $this->serializer->serialize($translation);
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
    public function store(string $entityTypeId, int|string $id, string $langcode, array $data): JsonApiDocument
    {
        $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        if ($entity instanceof JsonApiDocument) {
            return $entity;
        }

        if ($entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::conflict("Translation '{$langcode}' already exists for entity '{$id}'."),
            );
        }

        $attributes = $data['data']['attributes'] ?? [];

        // Create the translation by getting a new translation object and setting fields.
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableInterface) {
            foreach ($attributes as $field => $value) {
                $translation->set($field, $value);
            }
        }

        // Save the entity with its new translation.
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $storage->save($entity);

        $resource = $this->serializer->serialize($translation);
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
    public function update(string $entityTypeId, int|string $id, string $langcode, array $data): JsonApiDocument
    {
        $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        if ($entity instanceof JsonApiDocument) {
            return $entity;
        }

        if (!$entity->hasTranslation($langcode)) {
            return $this->errorDocument(
                JsonApiError::notFound("Translation '{$langcode}' not found for entity '{$id}'."),
            );
        }

        $attributes = $data['data']['attributes'] ?? [];

        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableInterface) {
            foreach ($attributes as $field => $value) {
                $translation->set($field, $value);
            }
        }

        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $storage->save($entity);

        $resource = $this->serializer->serialize($translation);
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
    public function destroy(string $entityTypeId, int|string $id, string $langcode): JsonApiDocument
    {
        $entity = $this->loadTranslatableEntity($entityTypeId, $id);
        if ($entity instanceof JsonApiDocument) {
            return $entity;
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

        // Remove the translation via removeTranslation if available.
        if (method_exists($entity, 'removeTranslation')) {
            $entity->removeTranslation($langcode);
            $storage = $this->entityTypeManager->getStorage($entityTypeId);
            $storage->save($entity);
        }

        return JsonApiDocument::empty(
            meta: ['deleted' => true, 'langcode' => $langcode],
            statusCode: 204,
        );
    }

    /**
     * Load an entity and validate it supports translations.
     *
     * @return TranslatableInterface|JsonApiDocument The entity if translatable, or an error document.
     */
    private function loadTranslatableEntity(string $entityTypeId, int|string $id): TranslatableInterface|JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return $this->errorDocument(
                JsonApiError::notFound("Unknown entity type: {$entityTypeId}."),
            );
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        if (!$entityType->isTranslatable()) {
            return $this->errorDocument(
                new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Entity type '{$entityTypeId}' does not support translations.",
                ),
            );
        }

        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $entity = $storage->load($id);

        if ($entity === null) {
            return $this->errorDocument(
                JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
            );
        }

        if (!$entity instanceof TranslatableInterface) {
            return $this->errorDocument(
                new JsonApiError(
                    status: '422',
                    title: 'Unprocessable Entity',
                    detail: "Entity '{$id}' does not implement TranslatableInterface.",
                ),
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
