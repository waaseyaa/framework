<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Relationship;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityInterface;
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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.relationship.traverse', $account);
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
        // canonical columns (RelationshipSchemaManager) are from_entity_type /
        // from_entity_id / to_entity_type / to_entity_id, with the bundle key
        // relationship_type — there is no source_type/source_id/type column.
        // This queries the "out" direction only (edges FROM the source); the
        // `direction` input is advisory-only for now (in/both traversal is a
        // separate feature, not this security fix).
        try {
            $repository = $this->entityTypeManager->getRepository('relationship');
            $criteria = [
                'from_entity_type' => $sourceType,
                'from_entity_id' => (string) $sourceId,
            ];
            if ($relationshipType !== null) {
                $criteria['relationship_type'] = $relationshipType;
            }
            $rows = $repository->findBy($criteria, [], 100);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('relationship.traverse: %s', $e->getMessage()));
        }

        $edges = [];
        foreach ($rows as $row) {
            // Per-entity 'view' gate on the edge record itself: never enumerate
            // a relationship the caller may not see. This tool is in the
            // DEFAULT anonymous MCP read allowlist, so an unauthenticated
            // caller reaches it — it must apply the same gate
            // EntityReadTool/EntityListTool/EntitySearchTool apply (fails
            // closed when enforcement is required but no handler is wired).
            if (!$this->canViewEntity($row, $account)) {
                continue;
            }

            $values = $this->extractValues($row);

            // Endpoint-identity gate: the edge record's own AccessPolicy says
            // nothing about whether the account may view the ENDPOINT entity
            // whose identity this row discloses (to_entity_type/to_entity_id
            // for an out-direction query — the source endpoint is the
            // caller's own query input, so its visibility is implied and is
            // not separately checked). Mirrors
            // RelationshipTraversalService::filterByEndpointVisibility()'s
            // fail-closed "prove NOTHING -> drop" contract at the tool layer:
            // an edge whose endpoint cannot be proven viewable is dropped
            // entirely, never partially disclosed.
            if (!$this->canViewEndpoint($values, $account)) {
                continue;
            }

            $values = $this->applyFieldAccessFilter($row, $values, $account);
            $edges[] = [
                'id' => $row->id(),
                'values' => $values,
            ];
        }

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['edges' => $edges, 'count' => count($edges)]]],
            summary: sprintf('Found %d relationships from %s/%s', count($edges), $sourceType, (string) $sourceId),
        );
    }

    /**
     * Prefer a curated getValues() when the edge entity provides one;
     * otherwise fall back to the EntityInterface-guaranteed toArray(), so the
     * endpoint fields (and every other stored field) surface for every
     * relationship entity implementation, not only those that happen to
     * define getValues() (mirrors EntityReadTool/EntitySearchTool). Without
     * this fallback to_entity_type/to_entity_id are never visible to the
     * endpoint gate below.
     *
     * @return array<string, mixed>
     */
    private function extractValues(EntityInterface $row): array
    {
        $values = [];
        if (method_exists($row, 'getValues')) {
            $curated = $row->getValues();
            $values = is_array($curated) ? $curated : [];
        }
        if ($values === []) {
            $values = $row->toArray();
        }

        return $values;
    }

    /**
     * Fail-closed gate on the non-source endpoint an edge discloses. For an
     * out-direction query the non-source endpoint is always
     * (to_entity_type, to_entity_id).
     *
     * Returns false (drop the edge) whenever the endpoint identity cannot be
     * determined, its entity type is unknown, or it fails to load — the tool
     * must never disclose an endpoint it cannot prove is viewable, mirroring
     * canViewEntity()'s own fail-closed branch. In capability-only mode (no
     * enforcement active) this preserves prior behavior and always allows,
     * since no per-entity gate was ever applied there.
     *
     * @param array<string, mixed> $values
     */
    private function canViewEndpoint(array $values, AccountInterface $account): bool
    {
        $endpointType = $values['to_entity_type'] ?? null;
        $endpointId = $values['to_entity_id'] ?? null;
        if (
            !is_string($endpointType) || $endpointType === ''
            || (!is_string($endpointId) && !is_int($endpointId)) || $endpointId === ''
        ) {
            return !$this->isAccessEnforced();
        }

        if (!$this->entityTypeManager->hasDefinition($endpointType)) {
            return !$this->isAccessEnforced();
        }

        try {
            $endpoint = $this->entityTypeManager->getRepository($endpointType)->find((string) $endpointId);
        } catch (\Throwable) {
            $endpoint = null;
        }
        if ($endpoint === null) {
            return !$this->isAccessEnforced();
        }

        return $this->canViewEntity($endpoint, $account);
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
