<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;

/**
 * @api
 */
final class RelationshipTraversalService
{
    private readonly RelationshipTopologyReader $topologyReader;

    private readonly RelationshipMaintenanceReader $maintenanceReader;

    /**
     * @param ?VisibilityFilterInterface $visibilityFilter Decides whether a
     *        related entity is publicly visible when `browse()` runs in
     *        `published`/`unpublished` mode. When null, related entities are
     *        treated as **non-public** (fail-closed), so an unwired caller
     *        leaks nothing — callers that surface related labels/paths to
     *        end users (the discovery API, SSR node pages) MUST pass a filter.
     * @param ?EntityAccessHandler $accessHandler Paired with $account to add a
     *        per-account 'view' gate on top of $visibilityFilter's publish-status
     *        gate (audit R5 residual #1, R7 WP2). The two gates are independent
     *        and both must pass: publish-status decides whether an endpoint
     *        matches the requested `published`/`unpublished` scope, while this
     *        gate decides whether the CALLER may see it at all — a published
     *        endpoint can still be access-restricted (e.g. a private node), and
     *        without this gate WorkflowVisibilityFilter alone would disclose its
     *        identity to anyone. When either $accessHandler or $account is
     *        null (the default), this gate is OFF and every endpoint is treated
     *        as viewable — exactly matching pre-fix behavior for callers that
     *        do not opt in (SSR nav applies its own post-filter instead; see
     *        SsrPageHandler::canViewRelatedEndpoint()). Fail-closed when
     *        engaged: an endpoint with an empty id/type, an unregistered
     *        entity type, or that fails to load is treated as NOT viewable.
     * @param ?AccountInterface $account The account the $accessHandler gate
     *        checks 'view' access against. Required together with
     *        $accessHandler to engage the gate.
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?VisibilityFilterInterface $visibilityFilter = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
        ?RelationshipTopologyReader $topologyReader = null,
        ?RelationshipMaintenanceReader $maintenanceReader = null,
    ) {
        $this->topologyReader = $topologyReader ?? new RelationshipTopologyReader();
        $this->maintenanceReader = $maintenanceReader ?? new RelationshipMaintenanceReader();
    }

    /**
     * Traverse relationship edges for an entity.
     *
     * Endpoint visibility matches browse(): in `published`/`unpublished` mode
     * every non-source endpoint of a returned relationship is checked against
     * the visibility filter, fail-closed — with no filter wired, no edge is
     * returned in those modes, so an unwired caller cannot leak the identity
     * (`to_entity_type`/`to_entity_id`) of entities the viewer cannot see.
     * Callers opting into `status: 'all'` own the exposure decision (system
     * context) exactly as they do with browse().
     *
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return list<Relationship>
     */
    public function traverse(string $entityType, int|string $entityId, array $options = []): array
    {
        $direction = $this->normalizeDirection($options['direction'] ?? 'both');
        $status = $this->normalizeStatus($options['status'] ?? 'published');
        $relationshipTypes = $this->normalizeRelationshipTypes($options['relationship_types'] ?? []);
        $at = $this->normalizeTemporal($options['at'] ?? null);
        $limit = $this->normalizeLimit($options['limit'] ?? null);

        $relationships = $this->queryRelationshipsForDirection(
            $entityType,
            (string) $entityId,
            $direction,
            $status,
            $relationshipTypes,
            null,
            null,
        );

        $relationships = $this->applyTemporalActiveFilterSortAndLimit($relationships, $at, $limit);

        return $this->filterByEndpointVisibility($relationships, $entityType, (string) $entityId, $status);
    }

    /**
     * Drop relationships whose non-source endpoints fail the visibility gate.
     *
     * Mirrors browse()'s fail-closed endpoint handling (mapTraversalRelationships),
     * but is direction-independent and strictly at-least-as-closed: a returned
     * Relationship entity exposes BOTH endpoint identities, so in `published`
     * mode every endpoint other than the queried source must be provably
     * public (and in `unpublished` mode provably non-public). Runs after the
     * temporal/sort/limit step, matching browse()'s ordering.
     *
     * @param list<Relationship> $relationships
     * @param 'published'|'unpublished'|'all' $statusMode
     * @return list<Relationship>
     */
    private function filterByEndpointVisibility(
        array $relationships,
        string $sourceEntityType,
        string $sourceEntityId,
        string $statusMode,
    ): array {
        if ($statusMode !== 'published' && $statusMode !== 'unpublished') {
            return $relationships;
        }

        // With no filter wired we can prove NOTHING about endpoint visibility
        // in either direction, so both modes fail fully closed. (Published
        // mode would drop every edge below anyway; unpublished mode must not
        // become fail-open — "not provably public" is not "provably draft".)
        if ($this->visibilityFilter === null) {
            return [];
        }

        /** @var array<string, array{label: string, is_public: bool, is_viewable: bool}> $entitySummaryCache */
        $entitySummaryCache = [];

        $pendingPairs = [];
        foreach ($relationships as $relationship) {
            foreach ($this->nonSourceEndpoints($relationship, $sourceEntityType, $sourceEntityId) as $pair) {
                $pendingPairs[] = $pair;
            }
        }
        $this->warmEntitySummariesForKeys($pendingPairs, $entitySummaryCache);

        $result = [];
        foreach ($relationships as $relationship) {
            $keep = true;
            foreach ($this->nonSourceEndpoints($relationship, $sourceEntityType, $sourceEntityId) as [$endpointType, $endpointId]) {
                $summary = $this->loadEntitySummaryCached($endpointType, $endpointId, $entitySummaryCache);
                if ($statusMode === 'published' && !$summary['is_public']) {
                    $keep = false;
                    break;
                }
                if ($statusMode === 'unpublished' && $summary['is_public']) {
                    $keep = false;
                    break;
                }
                // Access gate is ADDITIONAL to the publish-status gate above,
                // independent of $statusMode: a published-but-access-restricted
                // endpoint must still be withheld from a caller who cannot view
                // it (audit R5 residual #1). No-op when $accessHandler/$account
                // are not wired — see the constructor docblock.
                if (!$summary['is_viewable']) {
                    $keep = false;
                    break;
                }
            }
            if ($keep) {
                $result[] = $relationship;
            }
        }

        return $result;
    }

    /**
     * Endpoints of a relationship other than the queried source entity.
     *
     * A self-loop (both endpoints are the source) yields no pairs — the caller
     * already knows its own entity, nothing foreign is exposed. Empty endpoint
     * slots yield no pairs for the same reason.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function nonSourceEndpoints(
        Relationship $relationship,
        string $sourceEntityType,
        string $sourceEntityId,
    ): array {
        $pairs = [];
        $topology = $this->topology($relationship);
        $candidates = [
            [$topology->fromType, $topology->fromId],
            [$topology->toType, $topology->toId],
        ];

        foreach ($candidates as [$endpointType, $endpointId]) {
            if ($endpointType === '' || $endpointId === '') {
                continue;
            }
            if ($endpointType === $sourceEntityType && $endpointId === $sourceEntityId) {
                continue;
            }
            $pairs[$endpointType . ':' . $endpointId] = [$endpointType, $endpointId];
        }

        return array_values($pairs);
    }

    /**
     * Build a relationship navigation surface for a source entity.
     *
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null,
     *   temporal_from?: int|string|null,
     *   temporal_to?: int|string|null
     * } $options
     * @return array{
     *   source: array{type: string, id: string},
     *   outbound: list<array{
     *     relationship_id: string,
     *     relationship_type: string,
     *     direction: 'outbound'|'inbound',
     *     inverse: bool,
     *     directionality: string,
     *     related_entity_type: string,
     *     related_entity_id: string,
     *     related_entity_label: string,
     *     related_entity_path: string,
     *     status: int,
     *     weight: float|null,
     *     confidence: float|null,
     *     start_date: int|null,
     *     end_date: int|null
     *   }>,
     *   inbound: list<array{
     *     relationship_id: string,
     *     relationship_type: string,
     *     direction: 'outbound'|'inbound',
     *     inverse: bool,
     *     directionality: string,
     *     related_entity_type: string,
     *     related_entity_id: string,
     *     related_entity_label: string,
     *     related_entity_path: string,
     *     status: int,
     *     weight: float|null,
     *     confidence: float|null,
     *     start_date: int|null,
     *     end_date: int|null
     *   }>,
     *   counts: array{outbound: int, inbound: int, total: int}
     * }
     */
    public function browse(string $entityType, int|string $entityId, array $options = []): array
    {
        $sourceId = (string) $entityId;
        $normalized = [
            'relationship_types' => $this->normalizeRelationshipTypes($options['relationship_types'] ?? []),
            'status' => $this->normalizeStatus($options['status'] ?? 'published'),
            'at' => $this->normalizeTemporal($options['at'] ?? null),
            'limit' => $this->normalizeLimit($options['limit'] ?? null),
        ];
        $temporalFrom = $this->normalizeTemporal($options['temporal_from'] ?? null);
        $temporalTo = $this->normalizeTemporal($options['temporal_to'] ?? null);
        /** @var array<string, array{label: string, is_public: bool, is_viewable: bool}> $entitySummaryCache */
        $entitySummaryCache = [];

        $allRelationships = $this->queryRelationshipsForDirection(
            $entityType,
            $sourceId,
            'both',
            $normalized['status'],
            $normalized['relationship_types'],
            $temporalFrom,
            $temporalTo,
        );

        $outboundRels = [];
        $inboundRels = [];
        foreach ($allRelationships as $relationship) {
            if ($this->relationshipMatchesOutboundEndpoint($relationship, $entityType, $sourceId)) {
                $outboundRels[(string) $relationship->id()] = $relationship;
            }
            if ($this->relationshipMatchesInboundEndpoint($relationship, $entityType, $sourceId)) {
                $inboundRels[(string) $relationship->id()] = $relationship;
            }
        }

        $outboundOrdered = $this->orderRelationshipsByRid(array_values($outboundRels));
        $inboundOrdered = $this->orderRelationshipsByRid(array_values($inboundRels));

        $outboundProcessed = $this->applyTemporalActiveFilterSortAndLimit(
            $outboundOrdered,
            $normalized['at'],
            $normalized['limit'],
        );
        $inboundProcessed = $this->applyTemporalActiveFilterSortAndLimit(
            $inboundOrdered,
            $normalized['at'],
            $normalized['limit'],
        );

        $outboundEdges = $this->mapTraversalRelationships(
            relationships: $outboundProcessed,
            sourceEntityType: $entityType,
            sourceEntityId: $sourceId,
            direction: 'outbound',
            statusMode: $normalized['status'],
            entitySummaryCache: $entitySummaryCache,
        );

        $inboundEdges = $this->mapTraversalRelationships(
            relationships: $inboundProcessed,
            sourceEntityType: $entityType,
            sourceEntityId: $sourceId,
            direction: 'inbound',
            statusMode: $normalized['status'],
            entitySummaryCache: $entitySummaryCache,
        );

        return [
            'source' => [
                'type' => $entityType,
                'id' => $sourceId,
            ],
            'outbound' => $outboundEdges,
            'inbound' => $inboundEdges,
            'counts' => [
                'outbound' => count($outboundEdges),
                'inbound' => count($inboundEdges),
                'total' => count($outboundEdges) + count($inboundEdges),
            ],
        ];
    }

    /**
     * @param list<Relationship> $relationships
     * @return list<Relationship>
     */
    private function orderRelationshipsByRid(array $relationships): array
    {
        usort($relationships, static fn(Relationship $a, Relationship $b): int => (int) $a->id() <=> (int) $b->id());

        return $relationships;
    }

    /**
     * @param list<Relationship> $relationships
     * @return list<Relationship>
     */
    private function applyTemporalActiveFilterSortAndLimit(
        array $relationships,
        ?int $at,
        ?int $limit,
    ): array {
        $result = $relationships;
        if ($at !== null) {
            $result = array_values(array_filter(
                $result,
                fn(Relationship $relationship): bool => $this->isActiveAt($relationship, $at),
            ));
        }

        usort($result, function (Relationship $a, Relationship $b): int {
            $statusCmp = $this->statusSortValue($b) <=> $this->statusSortValue($a);
            if ($statusCmp !== 0) {
                return $statusCmp;
            }

            $aMaintenance = $this->maintenanceReader->read($a);
            $bMaintenance = $this->maintenanceReader->read($b);
            $weightCmp = $this->compareOptionalNumbersDesc($aMaintenance->weight, $bMaintenance->weight);
            if ($weightCmp !== 0) {
                return $weightCmp;
            }

            $startCmp = $this->compareOptionalTemporalsAsc($aMaintenance->startDate, $bMaintenance->startDate);
            if ($startCmp !== 0) {
                return $startCmp;
            }

            return (int) $a->id() <=> (int) $b->id();
        });

        // The limit is applied here in PHP, not as a SQL LIMIT, on purpose:
        // it must run AFTER the temporal `at` filter (isActiveAt() coerces
        // string dates via strtotime(), which has no portable SQL equivalent)
        // and AFTER the (status, weight, start_date, rid) re-sort above, which
        // differs from the query's `ORDER BY rid ASC`. Pushing LIMIT into the
        // SQL would slice by rid order before those steps and return wrong rows.
        if ($limit !== null) {
            return array_slice($result, 0, $limit);
        }

        return $result;
    }

    /**
     * @param list<string> $relationshipTypes
     * @return list<Relationship>
     */
    private function queryRelationshipsForDirection(
        string $entityType,
        string $entityId,
        string $direction,
        string $status,
        array $relationshipTypes,
        ?int $temporalFrom,
        ?int $temporalTo,
    ): array {
        $fromType = $this->relationshipFieldExpression('from_entity_type');
        $fromId = $this->relationshipFieldExpression('from_entity_id');
        $toType = $this->relationshipFieldExpression('to_entity_type');
        $toId = $this->relationshipFieldExpression('to_entity_id');
        $directionality = $this->relationshipFieldExpression('directionality');
        $statusField = $this->relationshipFieldExpression('status');
        $relationshipType = $this->relationshipFieldExpression('relationship_type');
        $conditions = [];
        $args = [];

        if ($direction === 'outbound') {
            $conditions[] = "(({$fromType} = ? AND {$fromId} = ?) OR ({$directionality} = ? AND {$toType} = ? AND {$toId} = ?))";
            array_push($args, $entityType, $entityId, 'bidirectional', $entityType, $entityId);
        } elseif ($direction === 'inbound') {
            $conditions[] = "(({$toType} = ? AND {$toId} = ?) OR ({$directionality} = ? AND {$fromType} = ? AND {$fromId} = ?))";
            array_push($args, $entityType, $entityId, 'bidirectional', $entityType, $entityId);
        } else {
            $conditions[] = "(({$fromType} = ? AND {$fromId} = ?) OR ({$toType} = ? AND {$toId} = ?))";
            array_push($args, $entityType, $entityId, $entityType, $entityId);
        }

        if ($status === 'published') {
            $conditions[] = "{$statusField} = CAST(? AS INTEGER)";
            $args[] = 1;
        } elseif ($status === 'unpublished') {
            $conditions[] = "{$statusField} = CAST(? AS INTEGER)";
            $args[] = 0;
        }

        if ($relationshipTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($relationshipTypes), '?'));
            $conditions[] = "{$relationshipType} IN ({$placeholders})";
            array_push($args, ...$relationshipTypes);
        }

        $this->appendTimelineOverlapSql($temporalFrom, $temporalTo, $conditions, $args);

        $sql = 'SELECT rid FROM relationship';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY rid ASC';

        $rows = iterator_to_array($this->database->query($sql, $args), false);
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int) ($row['rid'] ?? 0), $rows);
        // C-22 WP3: read path now goes through the canonical repository.
        // findMany() returns a plain list; re-key by id to preserve the lookup below.
        $loaded = [];
        foreach ($this->entityTypeManager->getRepository('relationship')->findMany($ids) as $loadedEntity) {
            $loaded[$loadedEntity->id()] = $loadedEntity;
        }

        $result = [];
        foreach ($ids as $idValue) {
            $entity = $loaded[$idValue] ?? null;
            if ($entity instanceof Relationship) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * Resolve a relationship field through the table's actual storage shape.
     * Fresh sql-blob installs keep non-key values in `_data`; older installs
     * may have the package's historical dedicated columns. Both routes feed
     * the same read query without mutating upgraded schemas.
     */
    private function relationshipFieldExpression(string $field): string
    {
        if ($this->database->schema()->fieldExists('relationship', $field)) {
            return $this->database->quoteIdentifier($field);
        }

        $expression = "json_extract(_data, '$.{$field}')";

        return in_array($field, ['status', 'start_date', 'end_date'], true)
            ? "CAST({$expression} AS INTEGER)"
            : $expression;
    }

    private function appendTimelineOverlapSql(?int $from, ?int $to, array &$conditions, array &$args): void
    {
        if ($from === null && $to === null) {
            return;
        }

        $start = $this->relationshipFieldExpression('start_date');
        $end = $this->relationshipFieldExpression('end_date');

        if ($from !== null && $to !== null) {
            $conditions[] = "({$start} IS NULL OR {$start} <= CAST(? AS INTEGER)) AND ({$end} IS NULL OR {$end} >= CAST(? AS INTEGER))";
            array_push($args, $to, $from);

            return;
        }

        if ($from !== null) {
            $conditions[] = "({$end} IS NULL OR {$end} >= CAST(? AS INTEGER))";
            $args[] = $from;

            return;
        }

        $conditions[] = "({$start} IS NULL OR {$start} <= CAST(? AS INTEGER))";
        $args[] = $to;
    }

    private function relationshipMatchesOutboundEndpoint(
        Relationship $relationship,
        string $entityType,
        string $entityId,
    ): bool {
        $topology = $this->topology($relationship);
        $maintenance = $this->maintenanceReader->read($relationship);
        $fromType = $topology->fromType;
        $fromId = $topology->fromId;
        $toType = $topology->toType;
        $toId = $topology->toId;
        $directionality = strtolower(trim($maintenance->directionality));

        if ($fromType === $entityType && $fromId === $entityId) {
            return true;
        }

        return $directionality === 'bidirectional' && $toType === $entityType && $toId === $entityId;
    }

    private function relationshipMatchesInboundEndpoint(
        Relationship $relationship,
        string $entityType,
        string $entityId,
    ): bool {
        $topology = $this->topology($relationship);
        $maintenance = $this->maintenanceReader->read($relationship);
        $fromType = $topology->fromType;
        $fromId = $topology->fromId;
        $toType = $topology->toType;
        $toId = $topology->toId;
        $directionality = strtolower(trim($maintenance->directionality));

        if ($toType === $entityType && $toId === $entityId) {
            return true;
        }

        return $directionality === 'bidirectional' && $fromType === $entityType && $fromId === $entityId;
    }

    /**
     * @param list<Relationship> $relationships
     * @param 'published'|'unpublished'|'all' $statusMode
     * @param array<string, array{label: string, is_public: bool, is_viewable: bool}> $entitySummaryCache
     * @return list<array{
     *   relationship_id: string,
     *   relationship_type: string,
     *   direction: 'outbound'|'inbound',
     *   inverse: bool,
     *   directionality: string,
     *   related_entity_type: string,
     *   related_entity_id: string,
     *   related_entity_label: string,
     *   related_entity_path: string,
     *   status: int,
     *   weight: float|null,
     *   confidence: float|null,
     *   start_date: int|null,
     *   end_date: int|null
     * }>
     */
    private function mapTraversalRelationships(
        array $relationships,
        string $sourceEntityType,
        string $sourceEntityId,
        string $direction,
        string $statusMode,
        array &$entitySummaryCache,
    ): array {
        $pendingPairs = [];

        foreach ($relationships as $relationship) {
            $pair = $this->resolveRelatedEndpointPair(
                $relationship,
                $sourceEntityType,
                $sourceEntityId,
                $direction,
            );
            if ($pair !== null) {
                $pendingPairs[] = $pair;
            }
        }

        $this->warmEntitySummariesForKeys($pendingPairs, $entitySummaryCache);

        $edges = [];

        foreach ($relationships as $relationship) {
            $topology = $this->topology($relationship);
            $maintenance = $this->maintenanceReader->read($relationship);
            $fromType = $topology->fromType;
            $fromId = $topology->fromId;
            $toType = $topology->toType;
            $toId = $topology->toId;
            $directionality = strtolower(trim($maintenance->directionality));

            $relatedType = '';
            $relatedId = '';
            $inverse = false;

            if ($direction === 'outbound') {
                if ($fromType === $sourceEntityType && $fromId === $sourceEntityId) {
                    $relatedType = $toType;
                    $relatedId = $toId;
                } elseif (
                    $directionality === 'bidirectional'
                    && $toType === $sourceEntityType
                    && $toId === $sourceEntityId
                ) {
                    $relatedType = $fromType;
                    $relatedId = $fromId;
                    $inverse = true;
                }
            } else {
                if ($toType === $sourceEntityType && $toId === $sourceEntityId) {
                    $relatedType = $fromType;
                    $relatedId = $fromId;
                } elseif (
                    $directionality === 'bidirectional'
                    && $fromType === $sourceEntityType
                    && $fromId === $sourceEntityId
                ) {
                    $relatedType = $toType;
                    $relatedId = $toId;
                    $inverse = true;
                }
            }

            if ($relatedType === '' || $relatedId === '') {
                continue;
            }

            $relatedSummary = $this->loadEntitySummaryCached($relatedType, $relatedId, $entitySummaryCache);
            if ($statusMode === 'published' && !$relatedSummary['is_public']) {
                continue;
            }
            if ($statusMode === 'unpublished' && $relatedSummary['is_public']) {
                continue;
            }
            // Access gate is ADDITIONAL to the publish-status gate above,
            // independent of $statusMode (see filterByEndpointVisibility()'s
            // matching comment and the constructor docblock).
            if (!$relatedSummary['is_viewable']) {
                continue;
            }

            $edges[] = [
                'relationship_id' => (string) $relationship->id(),
                'relationship_type' => (string) ($relationship->get('relationship_type') ?? ''),
                'direction' => $direction,
                'inverse' => $inverse,
                'directionality' => $directionality !== '' ? $directionality : 'directed',
                'related_entity_type' => $relatedType,
                'related_entity_id' => $relatedId,
                'related_entity_label' => $relatedSummary['label'],
                'related_entity_path' => sprintf('/%s/%s', $relatedType, $relatedId),
                'status' => $this->statusSortValue($relationship),
                'weight' => is_numeric($maintenance->weight) ? (float) $maintenance->weight : null,
                'confidence' => is_numeric($maintenance->confidence) ? (float) $maintenance->confidence : null,
                'start_date' => $this->normalizeTemporal($maintenance->startDate),
                'end_date' => $this->normalizeTemporal($maintenance->endDate),
            ];
        }

        return $edges;
    }

    /**
     * @return array{0: string, 1: string}|null Related entity type and id, or null if this relationship does not
     *                                       contribute an edge for the given source and direction.
     */
    private function resolveRelatedEndpointPair(
        Relationship $relationship,
        string $sourceEntityType,
        string $sourceEntityId,
        string $direction,
    ): ?array {
        $topology = $this->topology($relationship);
        $maintenance = $this->maintenanceReader->read($relationship);
        $fromType = $topology->fromType;
        $fromId = $topology->fromId;
        $toType = $topology->toType;
        $toId = $topology->toId;
        $directionality = strtolower(trim($maintenance->directionality));

        if ($direction === 'outbound') {
            if ($fromType === $sourceEntityType && $fromId === $sourceEntityId) {
                return [$toType, $toId];
            }
            if (
                $directionality === 'bidirectional'
                && $toType === $sourceEntityType
                && $toId === $sourceEntityId
            ) {
                return [$fromType, $fromId];
            }

            return null;
        }

        if ($toType === $sourceEntityType && $toId === $sourceEntityId) {
            return [$fromType, $fromId];
        }
        if (
            $directionality === 'bidirectional'
            && $fromType === $sourceEntityType
            && $fromId === $sourceEntityId
        ) {
            return [$toType, $toId];
        }

        return null;
    }

    /**
     * Batch-load entity summaries by type so relationship browsing does one query per
     * entity type instead of one per edge (N+1 avoidance).
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @param array<string, array{label: string, is_public: bool, is_viewable: bool}> $summaryCache
     */
    private function warmEntitySummariesForKeys(array $pairs, array &$summaryCache): void
    {
        $missingByType = [];

        foreach ($pairs as [$entityType, $entityId]) {
            if ($entityType === '' || $entityId === '') {
                continue;
            }

            $cacheKey = strtolower($entityType) . ':' . $entityId;
            if (isset($summaryCache[$cacheKey])) {
                continue;
            }

            if (!$this->entityTypeManager->hasDefinition($entityType)) {
                $summaryCache[$cacheKey] = [
                    'label' => sprintf('%s:%s', $entityType, $entityId),
                    'is_public' => false,
                    'is_viewable' => false,
                ];

                continue;
            }

            $missingByType[$entityType][$entityId] = true;
        }

        foreach ($missingByType as $entityType => $idSet) {
            $entityIds = array_keys($idSet);
            $resolvedIds = [];
            foreach ($entityIds as $eid) {
                $asInt = filter_var($eid, FILTER_VALIDATE_INT);
                $resolvedIds[] = $asInt !== false ? $asInt : $eid;
            }

            try {
                // C-22 WP3: read path now goes through the canonical repository.
                // findMany() returns a plain list; re-key by id to preserve the
                // int/string-tolerant lookups below.
                $loaded = [];
                foreach ($this->entityTypeManager->getRepository($entityType)->findMany($resolvedIds) as $loadedEntity) {
                    $loaded[$loadedEntity->id()] = $loadedEntity;
                }
            } catch (\Throwable) {
                foreach ($entityIds as $entityId) {
                    $cacheKey = strtolower($entityType) . ':' . $entityId;
                    if (!isset($summaryCache[$cacheKey])) {
                        $summaryCache[$cacheKey] = [
                            'label' => sprintf('%s:%s', $entityType, $entityId),
                            'is_public' => false,
                            'is_viewable' => false,
                        ];
                    }
                }

                continue;
            }

            foreach ($entityIds as $entityId) {
                $cacheKey = strtolower($entityType) . ':' . $entityId;
                if (isset($summaryCache[$cacheKey])) {
                    continue;
                }

                $resolvedInt = filter_var($entityId, FILTER_VALIDATE_INT);
                $resolvedId = $resolvedInt !== false ? $resolvedInt : $entityId;
                $entity = $loaded[$resolvedId] ?? null;
                if ($entity === null && is_int($resolvedId)) {
                    $entity = $loaded[(string) $resolvedId] ?? null;
                }
                if ($entity === null && is_string($resolvedId) && ctype_digit($resolvedId)) {
                    $entity = $loaded[(int) $resolvedId] ?? null;
                }

                if ($entity !== null) {
                    $label = trim($entity->label()) !== ''
                        ? $entity->label()
                        : sprintf('%s:%s', $entityType, $entityId);
                    $summaryCache[$cacheKey] = [
                        'label' => $label,
                        'is_public' => $this->isLoadedEntityPublic($entity),
                        'is_viewable' => $this->isEntityViewable($entity),
                    ];
                } else {
                    $summaryCache[$cacheKey] = [
                        'label' => sprintf('%s:%s', $entityType, $entityId),
                        'is_public' => false,
                        'is_viewable' => false,
                    ];
                }
            }
        }
    }

    /**
     * @return array{label: string, is_public: bool, is_viewable: bool}
     */
    private function loadEntitySummary(string $entityType, string $entityId): array
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return [
                'label' => sprintf('%s:%s', $entityType, $entityId),
                'is_public' => false,
                'is_viewable' => false,
            ];
        }

        try {
            // C-22 WP3: read path now goes through the canonical repository.
            $entity = $this->entityTypeManager->getRepository($entityType)->find($entityId);
            if ($entity !== null) {
                $label = trim($entity->label()) !== ''
                    ? $entity->label()
                    : sprintf('%s:%s', $entityType, $entityId);

                return [
                    'label' => $label,
                    'is_public' => $this->isLoadedEntityPublic($entity),
                    'is_viewable' => $this->isEntityViewable($entity),
                ];
            }
        } catch (\Throwable) {
            // Relationship browsing is best-effort for labels.
        }

        return [
            'label' => sprintf('%s:%s', $entityType, $entityId),
            'is_public' => false,
            'is_viewable' => false,
        ];
    }

    /**
     * @param array<string, array{label: string, is_public: bool, is_viewable: bool}> $summaryCache
     * @return array{label: string, is_public: bool, is_viewable: bool}
     */
    private function loadEntitySummaryCached(string $entityType, string $entityId, array &$summaryCache): array
    {
        $cacheKey = strtolower($entityType) . ':' . $entityId;
        if (isset($summaryCache[$cacheKey])) {
            return $summaryCache[$cacheKey];
        }

        $summaryCache[$cacheKey] = $this->loadEntitySummary($entityType, $entityId);

        return $summaryCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function isEntityPublic(string $entityType, array $values): bool
    {
        // Fail closed: with no visibility filter wired we cannot prove the
        // related entity is public, so it is treated as non-public and its
        // label/path is withheld from published/unpublished browse results.
        return $this->visibilityFilter?->isEntityPublic($entityType, $values) ?? false;
    }

    /**
     * Prefer a filter-owned closed projection so relationship discovery never
     * needs to probe a sealed publication field merely to decide visibility.
     */
    private function isLoadedEntityPublic(EntityInterface $entity): bool
    {
        if ($this->visibilityFilter instanceof EntityVisibilityFilterInterface) {
            return $this->visibilityFilter->isEntityPublicForEntity($entity);
        }

        return $this->isEntityPublic($entity->getEntityTypeId(), EntityValues::toCastAwareMap($entity));
    }

    /**
     * Per-account view gate for an endpoint entity the caller would otherwise
     * disclose (audit R5 residual #1, R7 WP2). Independent of publish status —
     * mirrors {@see \Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy}'s
     * and SsrPageHandler::canViewRelatedEndpoint()'s fail-closed "prove
     * viewable, else drop" contract.
     *
     * When $accessHandler/$account are not wired (the default), this gate is
     * OFF: every endpoint is treated as viewable, exactly matching pre-fix
     * behavior for callers that don't opt in. Opting in is additive to, never
     * a replacement for, the publish-status gate above.
     */
    private function isEntityViewable(EntityInterface $entity): bool
    {
        if ($this->accessHandler === null || $this->account === null) {
            return true;
        }

        return $this->accessHandler->check($entity, 'view', $this->account)->isAllowed();
    }

    private function normalizeDirection(mixed $direction): string
    {
        if (!is_string($direction)) {
            return 'both';
        }
        $value = strtolower(trim($direction));
        if (in_array($value, ['outbound', 'inbound', 'both'], true)) {
            return $value;
        }
        return 'both';
    }

    private function normalizeStatus(mixed $status): string
    {
        if (!is_string($status)) {
            return 'published';
        }
        $value = strtolower(trim($status));
        if (in_array($value, ['published', 'unpublished', 'all'], true)) {
            return $value;
        }
        return 'published';
    }

    /**
     * @param mixed $types
     * @return list<string>
     */
    private function normalizeRelationshipTypes(mixed $types): array
    {
        if (!is_array($types)) {
            return [];
        }

        $normalized = [];
        foreach ($types as $type) {
            if (!is_string($type)) {
                continue;
            }
            $value = trim($type);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeTemporal(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
            $timestamp = strtotime($trimmed);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function normalizeLimit(mixed $limit): ?int
    {
        if ($limit === null || $limit === '') {
            return null;
        }
        if (!is_numeric($limit)) {
            return null;
        }
        $value = (int) $limit;
        if ($value <= 0) {
            return null;
        }
        return $value;
    }

    private function isActiveAt(Relationship $relationship, int $at): bool
    {
        $maintenance = $this->maintenanceReader->read($relationship);
        $start = $this->normalizeTemporal($maintenance->startDate);
        $end = $this->normalizeTemporal($maintenance->endDate);

        if ($start !== null && $start > $at) {
            return false;
        }
        if ($end !== null && $end < $at) {
            return false;
        }

        return true;
    }

    private function statusSortValue(Relationship $relationship): int
    {
        return $this->maintenanceReader->read($relationship)->status;
    }

    private function topology(Relationship $relationship): RelationshipTopology
    {
        return $this->topologyReader->read($relationship);
    }

    private function compareOptionalNumbersDesc(mixed $a, mixed $b): int
    {
        $aNum = is_numeric($a) ? (float) $a : null;
        $bNum = is_numeric($b) ? (float) $b : null;

        if ($aNum === null && $bNum === null) {
            return 0;
        }
        if ($aNum === null) {
            return 1;
        }
        if ($bNum === null) {
            return -1;
        }

        return $bNum <=> $aNum;
    }

    private function compareOptionalTemporalsAsc(mixed $a, mixed $b): int
    {
        $aTs = $this->normalizeTemporal($a);
        $bTs = $this->normalizeTemporal($b);

        if ($aTs === null && $bTs === null) {
            return 0;
        }
        if ($aTs === null) {
            return 1;
        }
        if ($bTs === null) {
            return -1;
        }

        return $aTs <=> $bTs;
    }
}
