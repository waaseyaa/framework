<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class RelationshipTraversalService
{
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

        $conditions = [];
        $args = [];
        $id = (string) $entityId;

        if ($direction === 'outbound') {
            $conditions[] = '((from_entity_type = ? AND from_entity_id = ?) OR (directionality = ? AND to_entity_type = ? AND to_entity_id = ?))';
            array_push($args, $entityType, $id, 'bidirectional', $entityType, $id);
        } elseif ($direction === 'inbound') {
            $conditions[] = '((to_entity_type = ? AND to_entity_id = ?) OR (directionality = ? AND from_entity_type = ? AND from_entity_id = ?))';
            array_push($args, $entityType, $id, 'bidirectional', $entityType, $id);
        } else {
            $conditions[] = '((from_entity_type = ? AND from_entity_id = ?) OR (to_entity_type = ? AND to_entity_id = ?))';
            array_push($args, $entityType, $id, $entityType, $id);
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

        if ($limit !== null) {
            $result = array_slice($result, 0, $limit);
        }

        return $result;
    }

    /**
     * Build a relationship navigation surface for a source entity.
     *
     * @param array{
     *   relationship_types?: list<string>,
     *   status?: 'published'|'unpublished'|'all',
     *   at?: int|string|null,
     *   limit?: int|null
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
        /** @var array<string, array{label: string, is_public: bool}> $entitySummaryCache */
        $entitySummaryCache = [];

        $outboundEdges = $this->mapTraversalRelationships(
            relationships: $this->traverse($entityType, $sourceId, $normalized + ['direction' => 'outbound']),
            sourceEntityType: $entityType,
            sourceEntityId: $sourceId,
            direction: 'outbound',
            statusMode: $normalized['status'],
            entitySummaryCache: $entitySummaryCache,
        );

        $inboundEdges = $this->mapTraversalRelationships(
            relationships: $this->traverse($entityType, $sourceId, $normalized + ['direction' => 'inbound']),
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
                    'is_public' => $this->isEntityPublic($entityType, $entity->toArray()),
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
        return $this->visibilityFilter?->isEntityPublic($entityType, $values) ?? true;
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
