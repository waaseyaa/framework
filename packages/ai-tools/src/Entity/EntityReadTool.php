<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Read a single entity by type + id.
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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.read', $account);
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

        $forbidden = $this->requireEntityAccess($entity, 'view', $account);
        if ($forbidden !== null) {
            return $forbidden;
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => $this->serialize($entity)]],
            summary: sprintf('Loaded %s/%s', $entityType, $id),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        // Read-only — dryRun is the same as execute.
        return $this->execute($arguments, $account);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EntityInterface $entity): array
    {
        $data = [
            'entity_type' => $entity->getEntityTypeId(),
            'id' => $entity->id(),
        ];
        // FR-008 (optimistic-locking-01KTXCHY): expose the current head so a
        // caller can form an expectation for entity.update. Omitted when the
        // entity carries no revision id (absence = "no expectation formable").
        if (method_exists($entity, 'getRevisionId')) {
            $revisionId = $entity->getRevisionId();
            if ($revisionId !== null) {
                $data['revision_id'] = $revisionId;
            }
        }
        // Prefer a curated getValues() when an entity provides one; otherwise use
        // the EntityInterface-guaranteed toArray(), so field values are exposed
        // for every entity, not only those that happen to define getValues().
        $values = [];
        if (method_exists($entity, 'getValues')) {
            $curated = $entity->getValues();
            $values = is_array($curated) ? $curated : [];
        }
        if ($values === []) {
            $values = $entity->toArray();
        }
        if ($values !== []) {
            $data['values'] = $values;
        }

        return $data;
    }
}
