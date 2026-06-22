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
 *
 * Field-access scope: when an access handler and account are supplied, field
 * visibility is filtered against a *prototype* entity (a bare instance carrying
 * only the requested bundle key — see show()). The resulting `x-access-restricted`
 * markers and view-denied removals therefore reflect only STATIC, type/bundle-level
 * field policy, not instance-level decisions (owner-only fields, row-state gates).
 * Callers must not treat the rendered field set as a per-record access oracle.
 *
 * Route exposure: this endpoint (and /api/openapi.json) is registered option-less
 * by foundation's BuiltinRouteRegistrar and is intentionally anonymous-reachable —
 * see docs/specs/api-layer.md "Adjacent enumeration surfaces". Do not add auth here
 * without retiring that documented boundary.
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
     * GET /api/schema/{entity_type}[?bundle=...] — return JSON Schema for the
     * given entity type, scoped to a bundle when one is supplied so a content
     * type's distinct typed fields surface (e.g. page vs news).
     */
    public function show(string $entityTypeId, ?string $bundle = null): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return JsonApiDocument::fromErrors(
                [JsonApiError::notFound("Unknown entity type: {$entityTypeId}.")],
                statusCode: 404,
            );
        }

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $bundle = ($bundle === null || $bundle === '') ? null : $bundle;

        $entity = null;
        if ($this->accessHandler !== null && $this->account !== null) {
            $class = $entityType->getClass();
            // Build the prototype entity with the requested bundle so field
            // access checks evaluate against the right content type.
            $protoValues = [];
            $bundleKey = $entityType->getKeys()['bundle'] ?? null;
            if ($bundle !== null && $bundleKey !== null) {
                $protoValues[$bundleKey] = $bundle;
            }
            try {
                $entity = new $class($protoValues);
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
            $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $bundle),
            $entity,
            $this->accessHandler,
            $this->account,
        );

        $self = "/api/schema/{$entityTypeId}";
        if ($bundle !== null) {
            $self .= '?bundle=' . rawurlencode($bundle);
        }

        return new JsonApiDocument(
            meta: [
                'schema' => $schema,
            ],
            links: [
                'self' => $self,
            ],
            statusCode: 200,
        );
    }
}
