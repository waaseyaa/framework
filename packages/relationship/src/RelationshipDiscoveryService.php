<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

final class RelationshipDiscoveryService
{
    public function __construct(
        private readonly RelationshipTraversalService $traversalService,
    ) {}

    /**
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null,
     *   offset?: int|null
     * } $options
     * @return array<string, mixed>
     */
    public function topicHub(string $entityType, int|string $entityId, array $options = []): array
    {
        $limit = $this->normalizeLimit($options['limit'] ?? null, 20);
        $offset = $this->normalizeOffset($options['offset'] ?? null);

        $browse = $this->traversalService->browse($entityType, $entityId, [
            'relationship_types' => $this->normalizeRelationshipTypes($options['relationship_types'] ?? []),
            'status' => $this->normalizeStatus($options['status'] ?? 'published'),
            'at' => $options['at'] ?? null,
        ]);

        $edges = $this->sortedEdges($browse);
        $pagedEdges = array_slice($edges, $offset, $limit);

        return [
            'source' => $browse['source'],
            'items' => $pagedEdges,
            'facets' => [
                'relationship_types' => $this->buildRelationshipTypeFacets($edges),
                'related_entity_types' => $this->buildRelatedTypeFacets($edges),
            ],
            'page' => [
                'offset' => $offset,
                'limit' => $limit,
                'count' => count($pagedEdges),
                'total' => count($edges),
            ],
        ];
    }

    /**
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null,
     *   offset?: int|null
     * } $options
     * @return array<string, mixed>
     */
    public function clusterPage(string $entityType, int|string $entityId, array $options = []): array
    {
        $limit = $this->normalizeLimit($options['limit'] ?? null, 12);
        $offset = $this->normalizeOffset($options['offset'] ?? null);

        $browse = $this->traversalService->browse($entityType, $entityId, [
            'relationship_types' => $this->normalizeRelationshipTypes($options['relationship_types'] ?? []),
            'status' => $this->normalizeStatus($options['status'] ?? 'published'),
            'at' => $options['at'] ?? null,
        ]);

        $edges = $this->sortedEdges($browse);

        /** @var array<string, array<string, mixed>> $clusters */
        $clusters = [];
        foreach ($edges as $edge) {
            $relationshipType = (string) ($edge['relationship_type'] ?? '');
            $relatedType = (string) ($edge['related_entity_type'] ?? '');
            if ($relationshipType === '' || $relatedType === '') {
                continue;
            }

            $clusterKey = sprintf('%s::%s', $relationshipType, $relatedType);
            if (!isset($clusters[$clusterKey])) {
                $clusters[$clusterKey] = [
                    'cluster_key' => $clusterKey,
                    'relationship_type' => $relationshipType,
                    'related_entity_type' => $relatedType,
                    'count' => 0,
                    'related_entities' => [],
                ];
            }

            $clusters[$clusterKey]['count']++;
            $entityKey = sprintf(
                '%s:%s',
                (string) ($edge['related_entity_type'] ?? ''),
                (string) ($edge['related_entity_id'] ?? ''),
            );
            if (!isset($clusters[$clusterKey]['related_entities'][$entityKey])) {
                $clusters[$clusterKey]['related_entities'][$entityKey] = [
                    'type' => (string) ($edge['related_entity_type'] ?? ''),
                    'id' => (string) ($edge['related_entity_id'] ?? ''),
                    'label' => (string) ($edge['related_entity_label'] ?? ''),
                    'path' => (string) ($edge['related_entity_path'] ?? ''),
                ];
            }
        }

        $clusterList = array_values(array_map(function (array $cluster): array {
            $entities = array_values($cluster['related_entities']);
            usort($entities, static function (array $left, array $right): int {
                $labelCompare = strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
                if ($labelCompare !== 0) {
                    return $labelCompare;
                }
                $typeCompare = strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''));
                if ($typeCompare !== 0) {
                    return $typeCompare;
                }

                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            });

            return [
                'cluster_key' => $cluster['cluster_key'],
                'relationship_type' => $cluster['relationship_type'],
                'related_entity_type' => $cluster['related_entity_type'],
                'count' => (int) $cluster['count'],
                'related_entities' => $entities,
            ];
        }, $clusters));

        usort($clusterList, static function (array $left, array $right): int {
            $countCompare = ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0));
            if ($countCompare !== 0) {
                return $countCompare;
            }
            $typeCompare = strcmp((string) ($left['relationship_type'] ?? ''), (string) ($right['relationship_type'] ?? ''));
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcmp((string) ($left['related_entity_type'] ?? ''), (string) ($right['related_entity_type'] ?? ''));
        });

        $pagedClusters = array_slice($clusterList, $offset, $limit);

        return [
            'source' => $browse['source'],
            'clusters' => $pagedClusters,
            'page' => [
                'offset' => $offset,
                'limit' => $limit,
                'count' => count($pagedClusters),
                'total' => count($clusterList),
            ],
        ];
    }

    /**
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   from?: int|string|null,
     *   to?: int|string|null,
     *   limit?: int|null,
     *   offset?: int|null
     * } $options
     * @return array<string, mixed>
     */
    public function timeline(string $entityType, int|string $entityId, array $options = []): array
    {
        $limit = $this->normalizeLimit($options['limit'] ?? null, 20);
        $offset = $this->normalizeOffset($options['offset'] ?? null);
        $direction = $this->normalizeDirection($options['direction'] ?? 'both');
        $from = $this->normalizeTemporal($options['from'] ?? null);
        $to = $this->normalizeTemporal($options['to'] ?? null);
        $at = $this->normalizeTemporal($options['at'] ?? null);

        $browse = $this->traversalService->browse($entityType, $entityId, [
            'relationship_types' => $this->normalizeRelationshipTypes($options['relationship_types'] ?? []),
            'status' => $this->normalizeStatus($options['status'] ?? 'published'),
            'at' => $at,
        ]);

        $edges = [];
        if (in_array($direction, ['outbound', 'both'], true)) {
            $edges = array_merge($edges, is_array($browse['outbound'] ?? null) ? $browse['outbound'] : []);
        }
        if (in_array($direction, ['inbound', 'both'], true)) {
            $edges = array_merge($edges, is_array($browse['inbound'] ?? null) ? $browse['inbound'] : []);
        }

        $edges = array_values(array_filter(
            $edges,
            fn(array $edge): bool => $this->edgeOverlapsWindow($edge, $from, $to),
        ));

        usort($edges, static function (array $left, array $right): int {
            $leftTimeline = self::timelineSortDate($left);
            $rightTimeline = self::timelineSortDate($right);
            $timelineCompare = $leftTimeline <=> $rightTimeline;
            if ($timelineCompare !== 0) {
                return $timelineCompare;
            }

            $leftDirectionRank = ((string) ($left['direction'] ?? '')) === 'outbound' ? 0 : 1;
            $rightDirectionRank = ((string) ($right['direction'] ?? '')) === 'outbound' ? 0 : 1;
            if ($leftDirectionRank !== $rightDirectionRank) {
                return $leftDirectionRank <=> $rightDirectionRank;
            }

            $relationshipTypeCompare = strcmp((string) ($left['relationship_type'] ?? ''), (string) ($right['relationship_type'] ?? ''));
            if ($relationshipTypeCompare !== 0) {
                return $relationshipTypeCompare;
            }

            $relatedTypeCompare = strcmp((string) ($left['related_entity_type'] ?? ''), (string) ($right['related_entity_type'] ?? ''));
            if ($relatedTypeCompare !== 0) {
                return $relatedTypeCompare;
            }

            $relatedIdCompare = strcmp((string) ($left['related_entity_id'] ?? ''), (string) ($right['related_entity_id'] ?? ''));
            if ($relatedIdCompare !== 0) {
                return $relatedIdCompare;
            }

            return strcmp((string) ($left['relationship_id'] ?? ''), (string) ($right['relationship_id'] ?? ''));
        });

        $pagedEdges = array_slice($edges, $offset, $limit);
        $items = array_map(function (array $edge): array {
            $edge['timeline_date'] = self::timelineSortDate($edge);
            return $edge;
        }, $pagedEdges);

        return [
            'source' => $browse['source'],
            'items' => $items,
            'filters' => [
                'direction' => $direction,
                'from' => $from,
                'to' => $to,
                'at' => $at,
            ],
            'page' => [
                'offset' => $offset,
                'limit' => $limit,
                'count' => count($items),
                'total' => count($edges),
            ],
        ];
    }

    /**
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return array<string, mixed>
     */
    public function endpointPage(string $entityType, int|string $entityId, array $options = []): array
    {
        $browse = $this->traversalService->browse($entityType, $entityId, [
            'relationship_types' => $this->normalizeRelationshipTypes($options['relationship_types'] ?? []),
            'status' => $this->normalizeStatus($options['status'] ?? 'published'),
            'at' => $options['at'] ?? null,
            'limit' => $this->normalizeLimit($options['limit'] ?? null, 12),
        ]);

        return [
            'endpoint' => [
                'type' => $entityType,
                'id' => (string) $entityId,
                'path' => sprintf('/%s/%s', $entityType, (string) $entityId),
            ],
            'browse' => $browse,
        ];
    }

    /**
     * @param array<string, mixed> $relationshipValues
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return array<string, mixed>
     */
    public function relationshipEntityPage(array $relationshipValues, array $options = []): array
    {
        $fromType = trim((string) ($relationshipValues['from_entity_type'] ?? ''));
        $fromId = trim((string) ($relationshipValues['from_entity_id'] ?? ''));
        $toType = trim((string) ($relationshipValues['to_entity_type'] ?? ''));
        $toId = trim((string) ($relationshipValues['to_entity_id'] ?? ''));

        if ($fromType === '' || $fromId === '' || $toType === '' || $toId === '') {
            return [];
        }

        $status = $this->normalizeStatus($options['status'] ?? 'published');
        $relationshipTypes = $this->normalizeRelationshipTypes($options['relationship_types'] ?? []);
        $at = $options['at'] ?? null;
        $limit = $this->normalizeLimit($options['limit'] ?? null, 8);

        return [
            'from_endpoint' => $this->endpointPage($fromType, $fromId, [
                'status' => $status,
                'relationship_types' => $relationshipTypes,
                'at' => $at,
                'limit' => $limit,
            ]),
            'to_endpoint' => $this->endpointPage($toType, $toId, [
                'status' => $status,
                'relationship_types' => $relationshipTypes,
                'at' => $at,
                'limit' => $limit,
            ]),
            'edge_context' => [
                'relationship_type' => (string) ($relationshipValues['relationship_type'] ?? ''),
                'directionality' => (string) ($relationshipValues['directionality'] ?? 'directed'),
                'status' => is_numeric($relationshipValues['status'] ?? null) ? (int) $relationshipValues['status'] : 0,
                'weight' => is_numeric($relationshipValues['weight'] ?? null) ? (float) $relationshipValues['weight'] : null,
                'confidence' => is_numeric($relationshipValues['confidence'] ?? null) ? (float) $relationshipValues['confidence'] : null,
                'start_date' => $this->normalizeTemporal($relationshipValues['start_date'] ?? null),
                'end_date' => $this->normalizeTemporal($relationshipValues['end_date'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $browse
     * @return list<array<string, mixed>>
     */
    private function sortedEdges(array $browse): array
    {
        $edges = array_merge(
            is_array($browse['outbound'] ?? null) ? $browse['outbound'] : [],
            is_array($browse['inbound'] ?? null) ? $browse['inbound'] : [],
        );

        usort($edges, static function (array $left, array $right): int {
            $relationshipTypeCompare = strcmp((string) ($left['relationship_type'] ?? ''), (string) ($right['relationship_type'] ?? ''));
            if ($relationshipTypeCompare !== 0) {
                return $relationshipTypeCompare;
            }

            $leftDirectionRank = ((string) ($left['direction'] ?? '')) === 'outbound' ? 0 : 1;
            $rightDirectionRank = ((string) ($right['direction'] ?? '')) === 'outbound' ? 0 : 1;
            if ($leftDirectionRank !== $rightDirectionRank) {
                return $leftDirectionRank <=> $rightDirectionRank;
            }

            $entityTypeCompare = strcmp((string) ($left['related_entity_type'] ?? ''), (string) ($right['related_entity_type'] ?? ''));
            if ($entityTypeCompare !== 0) {
                return $entityTypeCompare;
            }

            $labelCompare = strcasecmp((string) ($left['related_entity_label'] ?? ''), (string) ($right['related_entity_label'] ?? ''));
            if ($labelCompare !== 0) {
                return $labelCompare;
            }

            $idCompare = strcmp((string) ($left['related_entity_id'] ?? ''), (string) ($right['related_entity_id'] ?? ''));
            if ($idCompare !== 0) {
                return $idCompare;
            }

            return strcmp((string) ($left['relationship_id'] ?? ''), (string) ($right['relationship_id'] ?? ''));
        });

        return $edges;
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @return list<array{key: string, count: int}>
     */
    private function buildRelationshipTypeFacets(array $edges): array
    {
        $counts = [];
        foreach ($edges as $edge) {
            $key = (string) ($edge['relationship_type'] ?? '');
            if ($key === '') {
                continue;
            }
            $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
        }

        return $this->sortedFacetCounts($counts);
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @return list<array{key: string, count: int}>
     */
    private function buildRelatedTypeFacets(array $edges): array
    {
        $counts = [];
        foreach ($edges as $edge) {
            $key = (string) ($edge['related_entity_type'] ?? '');
            if ($key === '') {
                continue;
            }
            $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
        }

        return $this->sortedFacetCounts($counts);
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{key: string, count: int}>
     */
    private function sortedFacetCounts(array $counts): array
    {
        $facets = [];
        foreach ($counts as $key => $count) {
            $facets[] = ['key' => $key, 'count' => $count];
        }

        usort($facets, static function (array $left, array $right): int {
            $countCompare = ((int) $right['count']) <=> ((int) $left['count']);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string) $left['key'], (string) $right['key']);
        });

        return $facets;
    }

    /**
     * @param mixed $relationshipTypes
     * @return list<string>
     */
    private function normalizeRelationshipTypes(mixed $relationshipTypes): array
    {
        if (!is_array($relationshipTypes)) {
            return [];
        }

        $normalized = [];
        foreach ($relationshipTypes as $relationshipType) {
            if (!is_string($relationshipType)) {
                continue;
            }
            $value = trim($relationshipType);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeStatus(mixed $status): string
    {
        if (!is_string($status)) {
            return 'published';
        }
        $value = strtolower(trim($status));

        return in_array($value, ['published', 'unpublished', 'all'], true) ? $value : 'published';
    }

    private function normalizeLimit(mixed $limit, int $default): int
    {
        if (!is_numeric($limit)) {
            return $default;
        }

        return max(1, min(100, (int) $limit));
    }

    private function normalizeOffset(mixed $offset): int
    {
        if (!is_numeric($offset)) {
            return 0;
        }

        return max(0, (int) $offset);
    }

    private function normalizeDirection(mixed $direction): string
    {
        if (!is_string($direction)) {
            return 'both';
        }
        $value = strtolower(trim($direction));
        if (!in_array($value, ['outbound', 'inbound', 'both'], true)) {
            return 'both';
        }

        return $value;
    }

    private function normalizeTemporal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        $parsed = strtotime($trimmed);
        if ($parsed === false) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $edge
     */
    private function edgeOverlapsWindow(array $edge, ?int $from, ?int $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }

        $start = is_numeric($edge['start_date'] ?? null) ? (int) $edge['start_date'] : null;
        $end = is_numeric($edge['end_date'] ?? null) ? (int) $edge['end_date'] : null;

        if ($to !== null && $start !== null && $start > $to) {
            return false;
        }

        if ($from !== null && $end !== null && $end < $from) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $edge
     */
    private static function timelineSortDate(array $edge): int
    {
        $start = is_numeric($edge['start_date'] ?? null) ? (int) $edge['start_date'] : null;
        if ($start !== null) {
            return $start;
        }

        $end = is_numeric($edge['end_date'] ?? null) ? (int) $edge['end_date'] : null;
        if ($end !== null) {
            return $end;
        }

        return PHP_INT_MAX;
    }
}
