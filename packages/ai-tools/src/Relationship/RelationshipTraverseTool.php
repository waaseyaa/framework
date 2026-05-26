<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Relationship;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Traverse the relationship graph starting from a source entity.
 *
 * Consumers without `waaseyaa/relationship` installed will receive a
 * structured `relationship_package_unavailable` error.
 *
 * @api
 */
#[AsAgentTool(
    name: 'relationship.traverse',
    capability: 'tool.relationship.traverse',
    destructive: false,
    dryRunSupported: true,
    category: 'relationship',
)]
final class RelationshipTraverseTool extends AbstractAgentTool
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function description(): string
    {
        return 'Traverse the relationship graph from a source entity.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'source_entity_type' => ['type' => 'string'],
                'source_id' => ['type' => ['string', 'integer']],
                'relationship_type' => ['type' => 'string'],
                'direction' => ['enum' => ['out', 'in', 'both']],
                'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            ],
            'required' => ['source_entity_type', 'source_id'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.relationship.traverse', $context);
        if ($denied !== null) {
            return $denied;
        }

        $sourceType = $arguments['source_entity_type'] ?? null;
        $sourceId = $arguments['source_id'] ?? null;
        if (!is_string($sourceType) || $sourceType === '' || (!is_string($sourceId) && !is_int($sourceId))) {
            return AgentToolResult::error('relationship.traverse: missing required arguments source_entity_type, source_id.');
        }

        // The relationship traversal service lives in waaseyaa/relationship
        // and is wired by the host kernel. In its absence (e.g. minimal
        // consumer install), the stock tool returns a clean unavailable
        // result so apps can swap in a richer implementation.
        if (!$this->entityTypeManager->hasDefinition('relationship')) {
            return AgentToolResult::error(
                message: 'relationship_package_unavailable',
                summary: 'Relationship traversal requires waaseyaa/relationship; install it or override the tool.',
            );
        }

        $relationshipType = isset($arguments['relationship_type']) && is_string($arguments['relationship_type'])
            ? $arguments['relationship_type']
            : null;

        // Look up relationship rows by source. The relationship package's
        // canonical fields are source_id + source_type + type + target_id.
        try {
            $repository = $this->entityTypeManager->getRepository('relationship');
            $criteria = [
                'source_type' => $sourceType,
                'source_id' => (string) $sourceId,
            ];
            if ($relationshipType !== null) {
                $criteria['type'] = $relationshipType;
            }
            $rows = $repository->findBy($criteria, [], 100);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('relationship.traverse: %s', $e->getMessage()));
        }

        // FR-002 / DIR-004: per-edge access check; omit forbidden relationship records silently.
        $edges = [];
        foreach ($rows as $row) {
            $accessResult = $context->entityAccessHandler->check($row, 'view', $context->account);
            if ($accessResult->isForbidden()) {
                continue;
            }
            $values = method_exists($row, 'getValues') ? $row->getValues() : [];
            $edges[] = [
                'id' => $row->id(),
                'values' => is_array($values) ? $values : [],
            ];
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['edges' => $edges, 'count' => count($edges)]]],
            summary: sprintf('Found %d relationships from %s/%s', count($edges), $sourceType, (string) $sourceId),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }
}
