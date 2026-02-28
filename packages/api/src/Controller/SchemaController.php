<?php

declare(strict_types=1);

namespace Aurora\Api\Controller;

use Aurora\Api\JsonApiDocument;
use Aurora\Api\JsonApiError;
use Aurora\Api\Schema\SchemaPresenter;
use Aurora\Entity\EntityTypeManagerInterface;

/**
 * Returns JSON Schema representations of entity types.
 *
 * GET /api/schema/{entity_type} — returns a JSON Schema with widget hints,
 * field metadata, and permission requirements.
 */
final class SchemaController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SchemaPresenter $schemaPresenter,
    ) {}

    /**
     * GET /api/schema/{entity_type} — return JSON Schema for the given entity type.
     */
    public function show(string $entityTypeId): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return JsonApiDocument::fromErrors(
                [JsonApiError::notFound("Unknown entity type: {$entityTypeId}.")],
                statusCode: 404,
            );
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $schema = $this->schemaPresenter->present($entityType);

        return new JsonApiDocument(
            meta: [
                'schema' => $schema,
            ],
            links: [
                'self' => "/api/schema/{$entityTypeId}",
            ],
            statusCode: 200,
        );
    }
}
