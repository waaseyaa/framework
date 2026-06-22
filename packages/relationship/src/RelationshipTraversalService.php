<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;

/**
 * @api
 */
final class RelationshipTraversalService
{
    /**
     * @param ?VisibilityFilterInterface $visibilityFilter Decides whether a
     *        related entity is publicly visible when `browse()` runs in
     *        `published`/`unpublished` mode. When null, related entities are
     *        treated as **non-public** (fail-closed), so an unwired caller
     *        leaks nothing — callers that surface related labels/paths to
     *        end users (the discovery API, SSR node pages) MUST pass a filter.
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?VisibilityFilterInterface $visibilityFilter = null,
    ) {}

    /**
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

        return $this->applyTemporalActiveFilterSortAndLimit($relationships, $at, $limit);
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
        /** @var array<string, array{label: string, is_public: bool}> $entitySummaryCache */
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

            $weightCmp = $this->compareOptionalNumbersDesc($a->get('weight'), $b->get('weight'));
            if ($weightCmp !== 0) {
                return $weightCmp;
            }

            $startCmp = $this->compareOptionalTemporalsAsc($a->get('start_date'), $b->get('start_date'));
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
        $conditions = [];
        $args = [];

        if ($direction === 'outbound') {
            $conditions[] = '((from_entity_type = ? AND from_entity_id = ?) OR (directionality = ? AND to_entity_type = ? AND to_entity_id = ?))';
            array_push($args, $entityType, $entityId, 'bidirectional', $entityType, $entityId);
        } elseif ($direction === 'inbound') {
            $conditions[] = '((to_entity_type = ? AND to_entity_id = ?) OR (directionality = ? AND from_entity_type = ? AND from_entity_id = ?))';
            array_push($args, $entityType, $entityId, 'bidirectional', $entityType, $entityId);
        } else {
            $conditions[] = '((from_entity_type = ? AND from_entity_id = ?) OR (to_entity_type = ? AND to_entity_id = ?))';
            array_push($args, $entityType, $entityId, $entityType, $entityId);
        }

        if ($status === 'published') {
            $conditions[] = 'status = ?';
            $args[] = 1;
        } elseif ($status === 'unpublished') {
            $conditions[] = 'status = ?';
            $args[] = 0;
        }

        if ($relationshipTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($relationshipTypes), '?'));
            $conditions[] = "relationship_type IN ({$placeholders})";
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
        $storage = $this->entityTypeManager->getStorage('relationship');
        $loaded = $storage->loadMultiple($ids);

        $result = [];
        foreach ($ids as $idValue) {
            $entity = $loaded[$idValue] ?? null;
            if ($entity instanceof Relationship) {
                $result[] = $entity;
            }
        }

        return $result;
    }

    private function appendTimelineOverlapSql(?int $from, ?int $to, array &$conditions, array &$args): void
    {
        if ($from === null && $to === null) {
            return;
        }

        if ($from !== null && $to !== null) {
            $conditions[] = '(start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?)';
            array_push($args, $to, $from);

            return;
        }

        if ($from !== null) {
            $conditions[] = '(end_date IS NULL OR end_date >= ?)';
            $args[] = $from;

            return;
        }

        $conditions[] = '(start_date IS NULL OR start_date <= ?)';
        $args[] = $to;
    }

    private function relationshipMatchesOutboundEndpoint(
        Relationship $relationship,
        string $entityType,
        string $entityId,
    ): bool {
        $fromType = (string) ($relationship->get('from_entity_type') ?? '');
        $fromId = (string) ($relationship->get('from_entity_id') ?? '');
        $toType = (string) ($relationship->get('to_entity_type') ?? '');
        $toId = (string) ($relationship->get('to_entity_id') ?? '');
        $directionality = strtolower(trim((string) ($relationship->get('directionality') ?? 'directed')));

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
        $fromType = (string) ($relationship->get('from_entity_type') ?? '');
        $fromId = (string) ($relationship->get('from_entity_id') ?? '');
        $toType = (string) ($relationship->get('to_entity_type') ?? '');
        $toId = (string) ($relationship->get('to_entity_id') ?? '');
        $directionality = strtolower(trim((string) ($relationship->get('directionality') ?? 'directed')));

        if ($toType === $entityType && $toId === $entityId) {
            return true;
        }

        return $directionality === 'bidirectional' && $fromType === $entityType && $fromId === $entityId;
    }

    /**
     * @param list<Relationship> $relationships
     * @param 'published'|'unpublished'|'all' $statusMode
     * @param array<string, array{label: string, is_public: bool}> $entitySummaryCache
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
            $fromType = (string) ($relationship->get('from_entity_type') ?? '');
            $fromId = (string) ($relationship->get('from_entity_id') ?? '');
            $toType = (string) ($relationship->get('to_entity_type') ?? '');
            $toId = (string) ($relationship->get('to_entity_id') ?? '');
            $directionality = strtolower(trim((string) ($relationship->get('directionality') ?? 'directed')));

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
                'weight' => is_numeric($relationship->get('weight')) ? (float) $relationship->get('weight') : null,
                'confidence' => is_numeric($relationship->get('confidence')) ? (float) $relationship->get('confidence') : null,
                'start_date' => $this->normalizeTemporal($relationship->get('start_date')),
                'end_date' => $this->normalizeTemporal($relationship->get('end_date')),
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
        $fromType = (string) ($relationship->get('from_entity_type') ?? '');
        $fromId = (string) ($relationship->get('from_entity_id') ?? '');
        $toType = (string) ($relationship->get('to_entity_type') ?? '');
        $toId = (string) ($relationship->get('to_entity_id') ?? '');
        $directionality = strtolower(trim((string) ($relationship->get('directionality') ?? 'directed')));

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
     * @param array<string, array{label: string, is_public: bool}> $summaryCache
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
                $storage = $this->entityTypeManager->getStorage($entityType);
                $loaded = $storage->loadMultiple($resolvedIds);
            } catch (\Throwable) {
                foreach ($entityIds as $entityId) {
                    $cacheKey = strtolower($entityType) . ':' . $entityId;
                    if (!isset($summaryCache[$cacheKey])) {
                        $summaryCache[$cacheKey] = [
                            'label' => sprintf('%s:%s', $entityType, $entityId),
                            'is_public' => false,
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
                        'is_public' => $this->isEntityPublic($entityType, EntityValues::toCastAwareMap($entity)),
                    ];
                } else {
                    $summaryCache[$cacheKey] = [
                        'label' => sprintf('%s:%s', $entityType, $entityId),
                        'is_public' => false,
                    ];
                }
            }
        }
    }

    /**
     * @return array{label: string, is_public: bool}
     */
    private function loadEntitySummary(string $entityType, string $entityId): array
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return [
                'label' => sprintf('%s:%s', $entityType, $entityId),
                'is_public' => false,
            ];
        }

        try {
            $storage = $this->entityTypeManager->getStorage($entityType);
            $resolvedId = ctype_digit($entityId) ? (int) $entityId : $entityId;
            $entity = $storage->load($resolvedId);
            if ($entity !== null) {
                $label = trim($entity->label()) !== ''
                    ? $entity->label()
                    : sprintf('%s:%s', $entityType, $entityId);

                return [
                    'label' => $label,
                    'is_public' => $this->isEntityPublic($entityType, EntityValues::toCastAwareMap($entity)),
                ];
            }
        } catch (\Throwable) {
            // Relationship browsing is best-effort for labels.
        }

        return [
            'label' => sprintf('%s:%s', $entityType, $entityId),
            'is_public' => false,
        ];
    }

    /**
     * @param array<string, array{label: string, is_public: bool}> $summaryCache
     * @return array{label: string, is_public: bool}
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
        $start = $this->normalizeTemporal($relationship->get('start_date'));
        $end = $this->normalizeTemporal($relationship->get('end_date'));

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
        $status = $relationship->get('status');
        if (is_bool($status)) {
            return $status ? 1 : 0;
        }
        if (is_numeric($status)) {
            return ((int) $status) === 0 ? 0 : 1;
        }
        if (is_string($status)) {
            $normalized = strtolower(trim($status));
            if (in_array($normalized, ['0', 'false'], true)) {
                return 0;
            }
            if (in_array($normalized, ['1', 'true'], true)) {
                return 1;
            }
        }

        return 0;
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
