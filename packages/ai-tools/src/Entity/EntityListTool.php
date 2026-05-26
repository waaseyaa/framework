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
 * List entities of a given type with optional filter / sort / limit.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.list',
    capability: 'tool.entity.list',
    destructive: false,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntityListTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'List entities of a given type with filter / sort / limit.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'filter' => ['type' => 'object', 'additionalProperties' => true],
                'sort' => ['type' => 'object', 'additionalProperties' => ['enum' => ['ASC', 'DESC']]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            ],
            'required' => ['entity_type'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.list', $context);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        if (!is_string($entityType) || $entityType === '') {
            return AgentToolResult::error('entity.list: missing required argument entity_type.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.list: unknown entity type "%s"', $entityType));
        }

        $filter = is_array($arguments['filter'] ?? null) ? $arguments['filter'] : [];
        $sort = is_array($arguments['sort'] ?? null) ? $arguments['sort'] : [];
        $limit = isset($arguments['limit']) && is_int($arguments['limit']) ? $arguments['limit'] : 50;

        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entities = $repository->findBy($filter, $sort, $limit);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.list: %s', $e->getMessage()));
        }

        // FR-002 / DIR-004: per-record access check; omit forbidden records silently.
        $items = [];
        foreach ($entities as $entity) {
            $accessResult = $context->entityAccessHandler->check($entity, 'view', $context->account);
            if (!$accessResult->isForbidden()) {
                $items[] = [
                    'entity_type' => $entity->getEntityTypeId(),
                    'id' => $entity->id(),
                ];
            }
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['items' => $items, 'count' => count($items)]]],
            summary: sprintf('Listed %d %s entities', count($items), $entityType),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }
}
