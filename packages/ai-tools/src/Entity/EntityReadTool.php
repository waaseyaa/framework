<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Read a single entity by type + id.
 *
 * Consults {@see \Waaseyaa\Access\EntityAccessHandler} for entity-level access
 * (view operation) per FR-002 / DIR-004. Returns a structured `accessDenied`
 * entry rather than a 403 when the entity-level policy forbids access.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.read',
    capability: 'tool.entity.read',
    destructive: false,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityReadTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Load a single entity by type and id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string', 'description' => 'Entity type id.'],
                'id' => ['type' => ['string', 'integer'], 'description' => 'Entity id.'],
                'langcode' => ['type' => 'string', 'description' => 'Optional language code.'],
            ],
            'required' => ['entity_type', 'id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.read', $context);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id))) {
            return AgentToolResult::error('entity.read: missing required arguments entity_type, id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.read: unknown entity type "%s"', $entityType));
        }

        $langcode = isset($arguments['langcode']) && is_string($arguments['langcode']) ? $arguments['langcode'] : null;

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id, $langcode);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.read: %s', $e->getMessage()));
        }

        if ($entity === null) {
            return AgentToolResult::error(sprintf('entity.read: %s/%s not found', $entityType, $id));
        }

        // FR-002 / DIR-004: entity-level access check per record.
        $accessResult = $context->entityAccessHandler->check($entity, 'view', $context->account);
        if ($accessResult->isForbidden()) {
            return AgentToolResult::success(
                content: [['type' => 'json', 'data' => [
                    'accessDenied' => true,
                    'entityType' => $entityType,
                    'id' => $id,
                    'reason' => 'entity_forbidden_for_account',
                ]]],
                summary: sprintf('Access denied for %s/%s', $entityType, $id),
            );
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => $this->serialize($entity, $context)]],
            summary: sprintf('Loaded %s/%s', $entityType, $id),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        // Read-only — dryRun is the same as execute.
        return $this->execute($arguments, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EntityInterface $entity, AgentToolContext $context): array
    {
        $data = [
            'entity_type' => $entity->getEntityTypeId(),
            'id' => $entity->id(),
        ];
        if (method_exists($entity, 'getValues')) {
            $values = $entity->getValues();
            if (is_array($values)) {
                // FR-002: field-level filtering via EntityAccessHandler.
                $allFields = array_keys($values);
                $allowedFields = $context->entityAccessHandler->filterFields(
                    $entity,
                    $allFields,
                    'view',
                    $context->account,
                );
                $allowedSet = array_flip($allowedFields);
                $filtered = [];
                foreach ($values as $field => $value) {
                    if (isset($allowedSet[$field])) {
                        $filtered[$field] = $value;
                    }
                    // Forbidden fields are silently omitted at entity-tool level;
                    // MCP redaction marker is the WP02 concern for the MCP surface.
                }
                $data['values'] = $filtered;
            }
        }

        return $data;
    }
}
