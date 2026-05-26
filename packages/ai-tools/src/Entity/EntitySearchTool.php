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
 * Full-text search across entities of a given type.
 *
 * Falls back to a LIKE-based scan via the entity query API when
 * `waaseyaa/search` is not installed in the consumer host.
 *
 * @api
 */
#[AsAgentTool(
    name: 'entity.search',
    capability: 'tool.entity.search',
    destructive: false,
    dryRunSupported: true,
    category: 'entity',
)]
final class EntitySearchTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Full-text or LIKE search across entities of a given type.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'entity_type' => ['type' => 'string'],
                'query' => ['type' => 'string', 'minLength' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['entity_type', 'query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.search', $context);
        if ($denied !== null) {
            return $denied;
        }

        $entityType = $arguments['entity_type'] ?? null;
        $query = $arguments['query'] ?? null;
        if (!is_string($entityType) || $entityType === '' || !is_string($query) || $query === '') {
            return AgentToolResult::error('entity.search: missing required arguments entity_type, query.');
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return AgentToolResult::error(sprintf('entity.search: unknown entity type "%s"', $entityType));
        }

        $limit = isset($arguments['limit']) && is_int($arguments['limit']) ? $arguments['limit'] : 20;

        // Stock implementation: walk recent entities via findBy and
        // filter client-side. Apps with `waaseyaa/search` installed will
        // typically replace this tool via the registry override.
        try {
            $repository = $this->entityTypeManager->getRepository($entityType);
            $candidates = $repository->findBy([], [], $limit * 4);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('entity.search: %s', $e->getMessage()));
        }

        $needle = mb_strtolower($query);
        $matches = [];
        foreach ($candidates as $entity) {
            if (count($matches) >= $limit) {
                break;
            }
            // FR-002 / DIR-004: skip forbidden records silently before content-matching.
            $accessResult = $context->entityAccessHandler->check($entity, 'view', $context->account);
            if ($accessResult->isForbidden()) {
                continue;
            }
            if ($this->matches($entity, $needle)) {
                $matches[] = ['entity_type' => $entity->getEntityTypeId(), 'id' => $entity->id()];
            }
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['items' => $matches, 'count' => count($matches)]]],
            summary: sprintf('Found %d %s matches for "%s"', count($matches), $entityType, $query),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }

    private function matches(EntityInterface $entity, string $needle): bool
    {
        if (!method_exists($entity, 'getValues')) {
            return false;
        }
        /** @var mixed $values */
        $values = $entity->getValues();
        if (!is_array($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (is_string($value) && str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }
}
