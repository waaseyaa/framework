<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * List the revision history of a revisionable entity, newest first.
 *
 * Read-only; shares the `tool.entity.read` capability and, when an access
 * handler is attached, the entity's `view` AccessPolicy.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.list_revisions',
    capability: 'tool.entity.read',
    destructive: false,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityListRevisionsTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'List the revision history of a revisionable entity (newest first).';
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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.read', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $id = $arguments['id'] ?? null;
        if (!is_string($entityType) || $entityType === '' || (!is_string($id) && !is_int($id))) {
            return AgentToolResult::error('entity.list_revisions: missing required arguments entity_type, id.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.list_revisions: unknown entity type "%s"', $entityType));
        }

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->find((string) $id);
            if ($entity === null) {
                return AgentToolResult::error(sprintf('entity.list_revisions: %s/%s not found', $entityType, (string) $id));
            }
            $forbidden = $this->requireEntityAccess($entity, 'view', $account);
            if ($forbidden !== null) {
                return $forbidden;
            }
            $revisions = $repository->listRevisions((string) $id);
        } catch (\LogicException $e) {
            return AgentToolResult::error(sprintf('entity.list_revisions: %s is not revisionable (%s)', $entityType, $e->getMessage()));
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.list_revisions: %s', $e->getMessage()));
        }

        $rows = [];
        foreach ($revisions as $revision) {
            $rows[] = [
                'revision_id' => method_exists($revision, 'getRevisionId') ? $revision->getRevisionId() : null,
                'label' => $revision->label(),
                'is_current' => method_exists($revision, 'isCurrentRevision') ? $revision->isCurrentRevision() : null,
            ];
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'revisions' => $rows]]],
            summary: sprintf('%d revision(s) for %s/%s', count($rows), $entityType, (string) $id),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        // Read-only — dryRun is the same as execute.
        return $this->execute($arguments, $account);
    }
}
