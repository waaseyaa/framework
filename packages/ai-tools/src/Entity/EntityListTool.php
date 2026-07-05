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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.entity.list', $account);
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

        // Per-entity access gate: drop entities the initiating account may not
        // view before they are serialized. No-op in capability-only mode (no
        // access handler attached). Result stays bounded by $limit.
        $entities = array_values(array_filter(
            $entities,
            fn(EntityInterface $e): bool => $this->canViewEntity($e, $account),
        ));

        $items = array_map(function (EntityInterface $e) use ($account): array {
            $item = [
                'entity_type' => $e->getEntityTypeId(),
                'id' => $e->id(),
            ];
            // Field-access-checked label (R7 WP1): $e->label() bypasses
            // field-level access, so under enforcement a restricted label-key
            // field is omitted rather than leaked. Defense in depth — this
            // tool's `tool.entity.list` capability is NOT on the public MCP
            // anonymous read tier (unlike entity.list_revisions), so this is
            // not anonymous-reachable, but the label channel is closed here too
            // for consistency.
            $label = $this->viewableLabel($e, $account, $this->entityTypeManager);
            if ($label !== null) {
                $item['label'] = $label;
            }
            // FR-008 (optimistic-locking-01KTXCHY): per-item current head so
            // a caller can form an expectation without a per-entity re-read
            // (entities are already loaded — zero added queries). Omitted when
            // no revision id exists.
            if (method_exists($e, 'getRevisionId')) {
                $revisionId = $e->getRevisionId();
                if ($revisionId !== null) {
                    $item['revision_id'] = $revisionId;
                }
            }

            return $item;
        }, $entities);

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['items' => $items, 'count' => count($items)]]],
            summary: sprintf('Listed %d %s entities', count($items), $entityType),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
