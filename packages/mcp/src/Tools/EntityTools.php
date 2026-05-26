<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Mcp\Serializer\McpEntityFieldFilter;

final class EntityTools extends McpTool
{
    private ?McpEntityFieldFilter $fieldFilter = null;

    /**
     * Inject the MCP field filter for field-level access enforcement (FR-005, FR-006).
     *
     * Called by McpController after construction. Kept as a setter rather than a
     * required constructor parameter to maintain the existing McpTool constructor shape
     * shared by all tool subclasses.
     */
    public function setFieldFilter(McpEntityFieldFilter $fieldFilter): void
    {
        $this->fieldFilter = $fieldFilter;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function getEntity(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : '';
        $id = $arguments['id'] ?? null;
        $resolvedId = is_numeric((string) $id) ? (int) $id : (string) $id;

        if ($this->fieldFilter !== null) {
            return $this->getEntityWithFieldRedaction($entityType, $resolvedId);
        }

        // Fallback: no field filter wired (e.g. tests that don't inject it) — delegate
        // to JsonApiController which uses the omit-on-forbidden JSON:API behaviour.
        $controller = new JsonApiController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        return $controller->show($entityType, $resolvedId)->toArray();
    }

    /**
     * Serialize an entity for MCP with field-level redaction (FR-005, FR-006).
     *
     * JSON:API omits forbidden fields (absent = denied). MCP preserves field presence
     * for audit lineage: forbidden fields are replaced by the canonical redaction marker
     * so callers know something was withheld. The two-step approach is:
     *
     * 1. Check entity-level access via JsonApiController (403 on entity denial).
     * 2. Serialize WITHOUT field-access filtering to get all non-internal attributes.
     * 3. Apply McpEntityFieldFilter to replace forbidden fields with the redaction marker.
     *
     * @param string     $entityType The entity type ID.
     * @param int|string $resolvedId The entity ID.
     * @return array<string, mixed>
     */
    private function getEntityWithFieldRedaction(string $entityType, int|string $resolvedId): array
    {
        // Step 1: Entity-level access check via JsonApiController.
        // Use null serializer args so the controller runs entity-level access only and
        // returns an error document on 404/403 before we attempt field serialization.
        $accessController = new JsonApiController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );
        $accessDoc = $accessController->show($entityType, $resolvedId);
        if ($accessDoc->errors !== []) {
            // Entity-level denied or not found — return error shape unchanged.
            return $accessDoc->toArray();
        }

        // Step 2: Load entity and serialize WITHOUT field-access filtering.
        // ResourceSerializer with no accessHandler/account returns all non-internal fields.
        // This gives us the complete attribute set to run McpEntityFieldFilter against.
        $entity = $this->loadEntityByTypeAndId($entityType, (string) $resolvedId);
        if (!$entity instanceof EntityInterface) {
            // Entity disappeared between the access check and load (race condition).
            return $accessDoc->toArray();
        }

        $resource = $this->serializer->serialize($entity);
        $resourceArray = $resource->toArray();

        // Step 3: Apply field-level redaction. Forbidden → redaction marker; Neutral/Allowed → value.
        $resourceArray = $this->fieldFilter->applyTo($resourceArray, $entity, $this->account);

        // Reconstruct the document envelope.
        return [
            'jsonapi' => ['version' => '1.1'],
            'data' => $resourceArray,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listEntityTypes(): array
    {
        $types = [];
        foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
            $types[] = [
                'id' => $id,
                'label' => $definition->getLabel(),
                'keys' => $definition->getKeys(),
                'fields' => $definition->getFieldDefinitions(),
            ];
        }

        return ['data' => $types];
    }
}
