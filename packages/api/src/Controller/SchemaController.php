<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Returns JSON Schema representations of entity types.
 *
 * GET /api/schema/{entity_type} — returns a JSON Schema with widget hints,
 * field metadata, and permission requirements.
 */
final class SchemaController
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SchemaPresenter $schemaPresenter,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

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

        $entity = null;
        if ($this->accessHandler !== null && $this->account !== null) {
            $class = $entityType->getClass();
            try {
                $entity = new $class([]);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'SchemaController: failed to create prototype entity for %s (%s): %s',
                    $entityTypeId,
                    $class,
                    $e->getMessage(),
                ));
            }
        }

        $schema = $this->schemaPresenter->present(
            $entityType,
            $entityType->getFieldDefinitions(),
            $entity,
            $this->accessHandler,
            $this->account,
        );

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
