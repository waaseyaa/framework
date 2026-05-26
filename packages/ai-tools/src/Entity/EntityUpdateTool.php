<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Load + mutate + save an existing entity.
 *
 * Destructive; the HITL gate applies.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.update',
    capability: 'tool.entity.update',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityUpdateTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Update fields of an existing entity by type + id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
                'values' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['entity_type', 'id', 'values'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $context);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        $values = $arguments['values'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id)) || !is_array($values)) {
            return AgentToolResult::error('entity.update: missing required arguments entity_type, id, values.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.update: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.update: %s/%s not found', $entityType, (string) $id));
            }
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.update: %s', $e->getMessage()));
        }

        // FR-002 / DIR-004: check update access before mutating.
        $accessResult = $context->entityAccessHandler->check($entity, 'update', $context->account);
        if ($accessResult->isForbidden()) {
            return AgentToolResult::success(
                content: [['type' => 'json', 'data' => [
                    'accessDenied' => true,
                    'entityType' => $entityType,
                    'id' => $id,
                    'reason' => 'entity_forbidden_for_account',
                ]]],
                summary: sprintf('Access denied: cannot update %s/%s', $entityType, (string) $id),
            );
        }

        try {
            foreach ($values as $field => $value) {
                if (!is_string($field)) {
                    continue;
                }
                $entity->set($field, $value);
            }
            $result = $repository->save($entity);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.update: %s', $e->getMessage()));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'result' => $result]]],
            summary: sprintf('Updated %s/%s', $entityType, (string) $id),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $context);
        if ($denied !== null) {
            return $denied;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_update' => $arguments]]],
            summary: 'Dry-run: would update entity',
        );
    }
}
