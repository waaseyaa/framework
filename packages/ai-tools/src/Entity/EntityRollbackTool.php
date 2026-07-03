<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Roll a revisionable entity back to a previous revision, recording the revert
 * as a new head revision (copy-forward, so history is preserved).
 *
 * Destructive; the HITL gate applies. Shares the `tool.entity.update`
 * capability and, when an access handler is attached, the entity's `update`
 * AccessPolicy.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.rollback',
    capability: 'tool.entity.update',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityRollbackTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Roll a revisionable entity back to a previous revision (records a new head revision).';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
                'target_revision_id' => ['type' => 'integer', 'description' => 'The revision to roll back to.'],
            ],
            'required' => ['entity_type', 'id', 'target_revision_id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        $targetRevisionId = $arguments['target_revision_id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id)) || !is_int($targetRevisionId)) {
            return AgentToolResult::error('entity.rollback: missing required arguments entity_type, id, target_revision_id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.rollback: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.rollback: %s/%s not found', $entityType, (string) $id));
            }
            $forbidden = $this->requireEntityAccess($entity, 'update', $account);
            if ($forbidden !== null) {
                return $forbidden;
            }
            // Security: rollback() writes the WHOLE target-revision row back
            // over the current entity, so it can silently re-apply a
            // privileged field (e.g. user.roles) the caller could never set
            // through entity.update directly. Gate only the fields the
            // restore would actually CHANGE — mirrors requireFieldEditAccess()
            // in EntityUpdateTool, run BEFORE the write.
            $targetRevision = $repository->loadRevision((string) $id, $targetRevisionId);
            if ($targetRevision !== null) {
                $changedValues = EntityRevisionRestoreGuard::changedValues(
                    EntityRevisionRestoreGuard::values($entity),
                    EntityRevisionRestoreGuard::values($targetRevision),
                );
                $fieldDenied = $this->requireFieldEditAccess($entity, $changedValues, $account);
                if ($fieldDenied !== null) {
                    return $fieldDenied;
                }
            }
            $reverted = $repository->rollback((string) $id, $targetRevisionId);
        } catch (\LogicException $e) {
            return AgentToolResult::error(sprintf('entity.rollback: %s is not revisionable (%s)', $entityType, $e->getMessage()));
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.rollback: %s', $e->getMessage()));
        }

        $newRevision = method_exists($reverted, 'getRevisionId') ? $reverted->getRevisionId() : null;

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'rolled_back_to' => $targetRevisionId, 'new_revision' => $newRevision]]],
            summary: sprintf('Rolled %s/%s back to revision %d', $entityType, (string) $id, $targetRevisionId),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $account);
        if ($denied !== null) {
            return $denied;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_rollback' => $arguments]]],
            summary: 'Dry-run: would roll back',
        );
    }
}
