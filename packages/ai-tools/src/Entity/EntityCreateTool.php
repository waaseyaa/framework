<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Create a new entity via {@see EntityRepositoryInterface::save()}.
 *
 * Consults {@see \Waaseyaa\Access\EntityAccessHandler} for entity-level create
 * access per FR-002 / DIR-004. Destructive (writes to persistent storage);
 * the HITL gate applies.
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
                'bundle' => ['type' => 'string', 'description' => 'Bundle (defaults to entity_type if omitted).'],
                'values' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['entity_type', 'values'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.create', $context);
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

        $bundle = isset($arguments['bundle']) && is_string($arguments['bundle']) ? $arguments['bundle'] : $entityType;

        // FR-002 / DIR-004: check create access before writing.
        $accessResult = $context->entityAccessHandler->checkCreateAccess($entityType, $bundle, $context->account);
        if ($accessResult->isForbidden()) {
            return AgentToolResult::success(
                content: [['type' => 'json', 'data' => [
                    'accessDenied' => true,
                    'entityType' => $entityType,
                    'id' => null,
                    'reason' => 'entity_forbidden_for_account',
                ]]],
                summary: sprintf('Access denied: cannot create %s', $entityType),
            );
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

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.create', $context);
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
