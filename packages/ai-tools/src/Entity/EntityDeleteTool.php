<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Hard-delete an entity by type + id.
 *
 * Destructive; the HITL gate applies.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.delete',
    capability: 'tool.entity.delete',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityDeleteTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Hard-delete an entity by type and id.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
            ],
            'required' => ['entity_type', 'id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.delete', $context);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id))) {
            return AgentToolResult::error('entity.delete: missing required arguments entity_type, id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.delete: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.delete: %s/%s not found', $entityType, (string) $id));
            }
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.delete: %s', $e->getMessage()));
        }

        // FR-002 / DIR-004: check delete access before removing.
        $accessResult = $context->entityAccessHandler->check($entity, 'delete', $context->account);
        if ($accessResult->isForbidden()) {
            return AgentToolResult::success(
                content: [['type' => 'json', 'data' => [
                    'accessDenied' => true,
                    'entityType' => $entityType,
                    'id' => $id,
                    'reason' => 'entity_forbidden_for_account',
                ]]],
                summary: sprintf('Access denied: cannot delete %s/%s', $entityType, (string) $id),
            );
        }

        try {
            $repository->delete($entity);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.delete: %s', $e->getMessage()));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'deleted' => true]]],
            summary: sprintf('Deleted %s/%s', $entityType, (string) $id),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.delete', $context);
        if ($denied !== null) {
            return $denied;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_delete' => $arguments]]],
            summary: 'Dry-run: would delete entity',
        );
    }
}
