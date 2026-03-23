<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\WorkflowVisibility;

final class DiscoveryTools extends McpTool
{
    private readonly WorkflowVisibility $workflowVisibility;

    public function __construct(
        EntityTypeManagerInterface $entityTypeManager,
        ResourceSerializer $serializer,
        EntityAccessHandler $accessHandler,
        AccountInterface $account,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider,
        WorkflowVisibility $workflowVisibility,
    ) {
        parent::__construct($entityTypeManager, $serializer, $accessHandler, $account);
        $this->workflowVisibility = $workflowVisibility;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function searchEntities(array $arguments): array
    {
        $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : 'node';
        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 10;

        $controller = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        $result = $controller->search($query, $entityType, $limit)->toArray();
        if (!isset($result['meta']) || !is_array($result['meta'])) {
            $result['meta'] = [];
        }
        $result['meta']['tool'] = 'search_entities';

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function aiDiscover(array $arguments): array
    {
        $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';
        if ($query === '') {
            throw new \InvalidArgumentException('AI discovery requires a non-empty "query" argument.');
        }

        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : 'node';
        if ($entityType === '') {
            throw new \InvalidArgumentException('AI discovery requires a non-empty "type" argument.');
        }

        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 10;
        $limit = max(1, min(100, $limit));

        $controller = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        $search = $controller->search($query, $entityType, $limit)->toArray();
        if (isset($search['errors'])) {
            $detail = is_string($search['errors'][0]['detail'] ?? null)
                ? $search['errors'][0]['detail']
                : 'AI discovery search failed.';
            throw new \InvalidArgumentException($detail);
        }

        $searchMeta = is_array($search['meta'] ?? null) ? $search['meta'] : [];
        $scoreBreakdown = is_array($searchMeta['score_breakdown'] ?? null) ? $searchMeta['score_breakdown'] : [];
        $breakdownByBaseRank = $this->sortScoreBreakdownByBaseRank($scoreBreakdown);
        $recommendations = [];
        $resultRows = is_array($search['data'] ?? null) ? $search['data'] : [];

        foreach ($resultRows as $index => $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $entityId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
            $breakdown = is_array($scoreBreakdown[$entityId] ?? null)
                ? $scoreBreakdown[$entityId]
                : ($breakdownByBaseRank[$index] ?? null);

            $recommendations[] = [
                'entity' => $resource,
                'explanation' => [
                    'semantic_score' => is_numeric($breakdown['semantic'] ?? null) ? (float) $breakdown['semantic'] : null,
                    'graph_context_score' => is_numeric($breakdown['graph_context'] ?? null) ? (float) $breakdown['graph_context'] : null,
                    'combined_score' => is_numeric($breakdown['combined'] ?? null) ? (float) $breakdown['combined'] : null,
                    'base_rank' => is_numeric($breakdown['base_rank'] ?? null) ? (int) $breakdown['base_rank'] : (int) $index,
                    'visibility_contract' => 'published_only',
                ],
            ];
        }

        $anchor = $this->resolveDiscoveryAnchor($arguments);
        $graphContext = $anchor === null ? null : $this->discoveryGraphContext($anchor['type'], $anchor['id']);

        return [
            'data' => [
                'recommendations' => $recommendations,
                'graph_context' => $graphContext,
            ],
            'meta' => [
                'tool' => 'ai_discover',
                'query' => $query,
                'type' => $entityType,
                'mode' => is_string($searchMeta['mode'] ?? null) ? $searchMeta['mode'] : 'unknown',
                'requested_limit' => $limit,
                'count' => count($recommendations),
                'anchor' => $anchor,
            ],
        ];
    }

    /**
     * @param array<string, array{semantic?: float, graph_context?: float, combined?: float, base_rank?: int}> $scoreBreakdown
     * @return list<array{semantic?: float, graph_context?: float, combined?: float, base_rank?: int}>
     */
    private function sortScoreBreakdownByBaseRank(array $scoreBreakdown): array
    {
        $rows = array_values($scoreBreakdown);
        usort($rows, static function (array $a, array $b): int {
            $aRank = is_numeric($a['base_rank'] ?? null) ? (int) $a['base_rank'] : PHP_INT_MAX;
            $bRank = is_numeric($b['base_rank'] ?? null) ? (int) $b['base_rank'] : PHP_INT_MAX;
            return $aRank <=> $bRank;
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{type: string, id: string}|null
     */
    private function resolveDiscoveryAnchor(array $arguments): ?array
    {
        $anchorType = is_string($arguments['anchor_type'] ?? null) ? strtolower(trim($arguments['anchor_type'])) : '';
        $anchorIdRaw = $arguments['anchor_id'] ?? null;
        $anchorId = is_scalar($anchorIdRaw) ? trim((string) $anchorIdRaw) : '';

        if ($anchorType === '' && $anchorId === '') {
            return null;
        }
        if ($anchorType === '' || $anchorId === '') {
            throw new \InvalidArgumentException('AI discovery anchor requires both non-empty "anchor_type" and "anchor_id".');
        }
        if (!$this->entityTypeManager->hasDefinition($anchorType)) {
            throw new \InvalidArgumentException(sprintf('Unknown anchor entity type: "%s".', $anchorType));
        }

        $entity = $this->loadEntityByTypeAndId($anchorType, $anchorId);
        if (!$entity instanceof EntityInterface) {
            throw new \InvalidArgumentException(sprintf('Anchor entity not found: %s:%s', $anchorType, $anchorId));
        }
        if (!$this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
            throw new \RuntimeException(sprintf('Anchor entity is not visible: %s:%s', $anchorType, $anchorId));
        }
        if ($anchorType === 'node' && !$this->isNodePublicForDiscovery($entity)) {
            throw new \RuntimeException(sprintf('Anchor entity is not public: %s:%s', $anchorType, $anchorId));
        }

        return [
            'type' => $anchorType,
            'id' => $anchorId,
        ];
    }

    /**
     * @return array{
     *   source: array{type: string, id: string},
     *   counts: array{outbound: int, inbound: int, total: int},
     *   relationship_types: array<string, int>
     * }
     */
    private function discoveryGraphContext(string $anchorType, string $anchorId): array
    {
        $rows = $this->collectTraversalRows([
            'entity_type' => $anchorType,
            'entity_id' => $anchorId,
            'direction' => 'both',
            'status' => 'published',
            'relationship_types' => [],
            'at' => null,
            'limit' => 100,
        ]);

        $counts = ['outbound' => 0, 'inbound' => 0, 'total' => 0];
        $types = [];
        foreach ($rows as $row) {
            $direction = $row['direction'] === 'inbound' ? 'inbound' : 'outbound';
            $counts[$direction]++;
            $counts['total']++;
            $relationshipType = strtolower((string) ($row['relationship']->get('relationship_type') ?? ''));
            if ($relationshipType === '') {
                continue;
            }
            $types[$relationshipType] = ($types[$relationshipType] ?? 0) + 1;
        }
        ksort($types);

        return [
            'source' => [
                'type' => $anchorType,
                'id' => $anchorId,
            ],
            'counts' => $counts,
            'relationship_types' => $types,
        ];
    }

    private function isNodePublicForDiscovery(EntityInterface $entity): bool
    {
        return $this->workflowVisibility->isNodePublic($entity->toArray());
    }
}
