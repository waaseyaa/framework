<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Create a new entity via {@see EntityRepositoryInterface::save()}.
 *
 * Destructive (writes to persistent storage); the HITL gate applies.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.create',
    capability: 'tool.entity.create',
    destructive: true,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityCreateTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Create a new entity with the supplied field values.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'values' => ['type' => 'object', 'additionalProperties' => true],
                'revision_log' => ['type' => 'string', 'description' => 'Optional revision log message (revisionable entities only).'],
            ],
            'required' => ['entity_type', 'values'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.create', $account);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $values = $arguments['values'] ?? null;
        if (!is_string($entityType) || $entityType === '' || !is_array($values)) {
            return AgentToolResult::error('entity.create: missing required arguments entity_type, values.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.create: unknown entity type "%s"', $entityType));
        }

        $forbidden = $this->requireCreateAccess($entityType, '', $account);
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $definition = $this->entityTypeManager->getDefinition($entityType);
            /** @var class-string $class */
            $class = $definition->getClass();
            if (!class_exists($class)) {
                return AgentToolResult::error(sprintf('entity.create: entity class %s not found', $class));
            }
            /** @var object $entity */
            $entity = new $class($values);
            // Critical gotcha (CLAUDE.md): force INSERT path when ID is supplied.
            if (method_exists($entity, 'enforceIsNew')) {
                $entity->enforceIsNew();
            }
            $revisionLog = $arguments['revision_log'] ?? null;
            if (is_string($revisionLog) && $revisionLog !== '' && method_exists($entity, 'setRevisionLog')) {
                $entity->setRevisionLog($revisionLog);
            }
            $repository = $this->entityTypeManager->getRepository($entityType);
            $result = $repository->save($entity);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.create: %s', $e->getMessage()));
        }

        $id = method_exists($entity, 'id') ? $entity->id() : null;

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['entity_type' => $entityType, 'id' => $id, 'result' => $result]]],
            summary: sprintf('Created %s/%s', $entityType, (string) $id),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.create', $account);
        if ($denied !== null) {
            return $denied;
        }
        $entityType = $arguments['entity_type'] ?? null;
        $values = $arguments['values'] ?? null;
        if (!is_string($entityType) || $entityType === '' || !is_array($values)) {
            return AgentToolResult::error('entity.create: missing required arguments entity_type, values.');
        }
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.create: unknown entity type "%s"', $entityType));
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['would_create' => ['entity_type' => $entityType, 'values' => $values]]]],
            summary: sprintf('Dry-run: would create %s entity', $entityType),
        );
    }
}
