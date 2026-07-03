<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Make an existing revision the current/default one for a revisionable entity,
 * without recording a new revision (re-points the base table in place).
 *
 * Destructive (changes which version is served); the HITL gate applies. Shares
 * the `tool.entity.update` capability and, when an access handler is attached,
 * the entity's `update` AccessPolicy.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.set_current_revision',
    capability: 'tool.entity.update',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntitySetCurrentRevisionTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Set which existing revision is current for a revisionable entity (no new revision).';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'id' => ['type' => ['string', 'integer']],
                'revision_id' => ['type' => 'integer', 'description' => 'The revision to make current.'],
            ],
            'required' => ['entity_type', 'id', 'revision_id'],
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
        $revisionId = $arguments['revision_id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id)) || !is_int($revisionId)) {
            return AgentToolResult::error('entity.set_current_revision: missing required arguments entity_type, id, revision_id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.set_current_revision: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.set_current_revision: %s/%s not found', $entityType, (string) $id));
            }
            $forbidden = $this->requireEntityAccess($entity, 'update', $account);
            if ($forbidden !== null) {
                return $forbidden;
            }
            // Security: setCurrentRevision() re-points the base table at the
            // WHOLE target-revision row, so it can silently re-apply a
            // privileged field (e.g. user.roles) the caller could never set
            // through entity.update directly. Gate only the fields the
            // restore would actually CHANGE — mirrors requireFieldEditAccess()
            // in EntityUpdateTool, run BEFORE the write.
            $targetRevision = $repository->loadRevision((string) $id, $revisionId);
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
            $current = $repository->setCurrentRevision((string) $id, $revisionId);
            unset($current);
        } catch (\LogicException $e) {
            return AgentToolResult::error(sprintf('entity.set_current_revision: %s is not revisionable (%s)', $entityType, $e->getMessage()));
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.set_current_revision: %s', $e->getMessage()));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'current_revision' => $revisionId]]],
            summary: sprintf('Set %s/%s current revision to %d', $entityType, (string) $id, $revisionId),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.update', $account);
        if ($denied !== null) {
            return $denied;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_set_current_revision' => $arguments]]],
            summary: 'Dry-run: would set current revision',
        );
    }
}
