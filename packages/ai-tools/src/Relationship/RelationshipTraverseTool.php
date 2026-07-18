<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Relationship;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\Entity\EntityFieldRedaction;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;

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

        // Source-entity 'view' gate (R8-c, MCP surface): the source is the
        // caller's own query INPUT, but supplying an id does NOT imply the
        // caller may view that entity — treating it as "implied viewable" is
        // the confused-deputy / existence-oracle pattern this closes.
        // `tool.relationship.traverse` is in the DEFAULT anonymous MCP read
        // allowlist (PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES), so an
        // anonymous caller could otherwise supply a restricted source id and,
        // if it has any published edge to a viewable entity, receive a
        // non-empty edges array echoing that restricted source id — confirming
        // the restricted entity exists and has that relationship (the same
        // disclosure the DiscoveryRouter hub/cluster/timeline gate closes on
        // HTTP). When the source is not viewable, not loadable, or its type is
        // unknown, return an EMPTY result — INDISTINGUISHABLE from "source has
        // no relationships" / "source absent". Fail-closed under enforcement
        // with no handler; capability-only mode (no handler, not enforced)
        // keeps prior behavior via canViewEntity()/isAccessEnforced().
        if (!$this->canViewSource($sourceType, $sourceId, $account)) {
            return $this->emptyTraversalResult($sourceType, $sourceId);
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

            $values = $this->extractValues($row, $account);

            // Endpoint-identity gate: the edge record's own AccessPolicy says
            // nothing about whether the account may view the ENDPOINT entity
            // whose identity this row discloses (to_entity_type/to_entity_id
            // for an out-direction query). The SOURCE endpoint is gated up
            // front by canViewSource() above (R8-c) — supplying its id is NOT
            // proof the caller may view it, so it is no longer treated as
            // "implied viewable". Mirrors
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
    private function extractValues(EntityInterface $row, AccountInterface $account): array
    {
        if ($row instanceof EntityBase) {
            $names = EntityFieldRedaction::ordinaryFieldNames($this->entityTypeManager, $row);
            $allowed = $this->applyFieldAccessFilter($row, array_fill_keys($names, true), $account);

            return EntityValues::toCastAwareMap(
                $row,
                array_keys($allowed),
            );
        }

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
     * Fail-closed 'view' gate on the SOURCE entity the caller named (R8-c).
     * Returns false (→ empty result) whenever the source cannot be proven
     * viewable: its type is unknown, it fails to load, or its AccessPolicy
     * denies 'view'. Mirrors {@see canViewEndpoint()} exactly — the source is
     * NOT special-cased as "implied viewable" just because the caller supplied
     * its id. In capability-only mode (no handler, enforcement not required)
     * this allows, preserving prior behavior; enforced-with-no-handler fails
     * closed.
     */
    private function canViewSource(string $sourceType, string|int $sourceId, AccountInterface $account): bool
    {
        if ($sourceType === '' || (string) $sourceId === '') {
            return !$this->isAccessEnforced();
        }

        if (!$this->entityTypeManager->hasDefinition($sourceType)) {
            return !$this->isAccessEnforced();
        }

        try {
            $source = $this->entityTypeManager->getRepository($sourceType)->find((string) $sourceId);
        } catch (\Throwable) {
            $source = null;
        }
        if ($source === null) {
            return !$this->isAccessEnforced();
        }

        return $this->canViewEntity($source, $account);
    }

    /**
     * The empty-edges success result returned when the source is not viewable.
     * Byte-identical in `data` to a source with genuinely zero relationships,
     * so a restricted source is indistinguishable from an empty/absent one (no
     * existence oracle). The summary echoes only the caller's own input id.
     */
    private function emptyTraversalResult(string $sourceType, string|int $sourceId): AgentToolResult
    {
        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['edges' => [], 'count' => 0]]],
            summary: sprintf('Found 0 relationships from %s/%s', $sourceType, (string) $sourceId),
        );
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
