<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Workflows\WorkflowVisibility;

final class SearchController
{
    public const string CONTRACT_VERSION = 'v1.0';
    public const string CONTRACT_SURFACE = 'semantic_search';
    public const string CONTRACT_STABILITY = 'stable';

    private const float DEFAULT_SEMANTIC_WEIGHT = 1.0;
    private const float DEFAULT_GRAPH_WEIGHT = 0.001;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function search(string $query, string $entityTypeId, int $limit = 10): JsonApiDocument
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return JsonApiDocument::fromErrors(
                [JsonApiError::notFound("Unknown entity type: {$entityTypeId}.")],
                statusCode: 404,
            );
        }

        $query = trim($query);
        if ($query === '') {
            return JsonApiDocument::fromCollection([], meta: ['query' => '', 'mode' => 'empty']);
        }

        $limit = max(1, min(100, $limit));
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $mode = $this->embeddingProvider !== null ? 'semantic' : 'keyword';
        $fallbackReason = null;
        $requestedMode = $mode;

        $semanticScores = [];
        if ($this->embeddingProvider !== null) {
            $semanticResult = $this->semanticSearchIds($query, $entityTypeId, $limit);
            if ($semanticResult['fallback_reason'] !== null) {
                $mode = 'keyword';
                $fallbackReason = $semanticResult['fallback_reason'];
                $ids = $this->keywordSearchIds($storage, $query, $limit);
            } else {
                $ids = $semanticResult['ids'];
                $semanticScores = $semanticResult['scores'];
            }
        } else {
            $ids = $this->keywordSearchIds($storage, $query, $limit);
        }

        $graphRerankApplied = false;
        $hybridScores = [];
        $graphContextCounts = [];
        if ($mode === 'semantic' && $ids !== []) {
            $graphRerank = $this->rerankWithRelationshipContext($entityTypeId, $ids, $semanticScores);
            $ids = $graphRerank['ids'];
            $graphRerankApplied = $graphRerank['applied'];
            $hybridScores = $graphRerank['scores'];
            $graphContextCounts = $graphRerank['graph_context_counts'];
        }

        $entities = $ids !== [] ? $storage->loadMultiple($ids) : [];

        $orderedEntities = [];
        foreach ($ids as $id) {
            if (isset($entities[$id])) {
                $orderedEntities[] = $entities[$id];
            }
        }

        $orderedEntities = array_values(array_filter(
            $orderedEntities,
            fn($entity) => $this->isEntitySearchVisible($entity),
        ));

        if ($this->accessHandler !== null && $this->account !== null) {
            $orderedEntities = array_values(array_filter(
                $orderedEntities,
                fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
            ));
        }

        $resources = $this->serializer->serializeCollection($orderedEntities, $this->accessHandler, $this->account);

        $meta = [
            'contract_version' => self::CONTRACT_VERSION,
            'contract_surface' => self::CONTRACT_SURFACE,
            'contract_stability' => self::CONTRACT_STABILITY,
            'semantic_extension_hooks' => ['graph_context_rerank'],
            'query' => $query,
            'type' => $entityTypeId,
            'limit' => $limit,
            'mode' => $mode,
        ];
        if ($fallbackReason !== null) {
            $meta['requested_mode'] = $requestedMode;
            $meta['fallback_reason'] = $fallbackReason;
        }
        if ($graphRerankApplied) {
            $meta['ranking'] = 'semantic+graph_context';
            $meta['ranking_weights'] = [
                'semantic' => self::DEFAULT_SEMANTIC_WEIGHT,
                'graph_context' => self::DEFAULT_GRAPH_WEIGHT,
            ];
            $meta['score_breakdown'] = $hybridScores;
            $meta['graph_context_counts'] = $graphContextCounts;
        }

        return JsonApiDocument::fromCollection($resources, meta: $meta);
    }

    /**
     * @param array<int|string> $ids
     * @param array<string, float> $semanticScores
     * @return array{
     *   ids: array<int|string>,
     *   applied: bool,
     *   scores: array<string, array{
     *     semantic: float,
     *     graph_context: float,
     *     combined: float,
     *     base_rank: int
     *   }>,
     *   graph_context_counts: array<string, int>
     * }
     */
    private function rerankWithRelationshipContext(string $entityTypeId, array $ids, array $semanticScores): array
    {
        if (!$this->entityTypeManager->hasDefinition('relationship')) {
            return [
                'ids' => $ids,
                'applied' => false,
                'scores' => [],
                'graph_context_counts' => [],
            ];
        }

        $baseOrder = [];
        foreach ($ids as $index => $id) {
            $key = (string) $id;
            $baseOrder[$key] = $index;
        }

        try {
            $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
            $relationshipIds = $relationshipStorage->getQuery()->accessCheck(false)->execute();
            if ($relationshipIds === []) {
                return [
                    'ids' => $ids,
                    'applied' => false,
                    'scores' => [],
                    'graph_context_counts' => [],
                ];
            }

            $relationshipEntities = $relationshipStorage->loadMultiple($relationshipIds);
        } catch (\Throwable) {
            return [
                'ids' => $ids,
                'applied' => false,
                'scores' => [],
                'graph_context_counts' => [],
            ];
        }

        $relationshipScores = [];
        foreach ($ids as $id) {
            $relationshipScores[(string) $id] = 0;
        }

        foreach ($relationshipEntities as $relationship) {
            $values = $relationship->toArray();
            if ((int) ($values['status'] ?? 0) !== 1) {
                continue;
            }

            $fromType = (string) ($values['from_entity_type'] ?? '');
            $toType = (string) ($values['to_entity_type'] ?? '');
            $fromId = (string) ($values['from_entity_id'] ?? '');
            $toId = (string) ($values['to_entity_id'] ?? '');

            if ($fromType === $entityTypeId && isset($relationshipScores[$fromId])) {
                $relationshipScores[$fromId] += 1;
            }
            if ($toType === $entityTypeId && isset($relationshipScores[$toId])) {
                $relationshipScores[$toId] += 1;
            }
        }

        $combinedScores = [];
        $scoreBreakdown = [];
        foreach ($ids as $id) {
            $key = (string) $id;
            $semantic = $semanticScores[$key] ?? (1.0 - (($baseOrder[$key] ?? 0) * 0.0001));
            $graphContext = ($relationshipScores[$key] ?? 0) * self::DEFAULT_GRAPH_WEIGHT;
            $combinedScores[$key] = ($semantic * self::DEFAULT_SEMANTIC_WEIGHT) + $graphContext;
            $scoreBreakdown[$key] = [
                'semantic' => round($semantic, 6),
                'graph_context' => round($graphContext, 6),
                'combined' => round($combinedScores[$key], 6),
                'base_rank' => (int) ($baseOrder[$key] ?? PHP_INT_MAX),
            ];
        }

        $reranked = $ids;
        usort($reranked, function (int|string $a, int|string $b) use ($combinedScores, $baseOrder): int {
            $aKey = (string) $a;
            $bKey = (string) $b;
            $scoreCmp = ($combinedScores[$bKey] ?? 0.0) <=> ($combinedScores[$aKey] ?? 0.0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return ($baseOrder[$aKey] ?? PHP_INT_MAX) <=> ($baseOrder[$bKey] ?? PHP_INT_MAX);
        });

        if ($reranked === $ids) {
            return [
                'ids' => $ids,
                'applied' => false,
                'scores' => [],
                'graph_context_counts' => [],
            ];
        }

        return [
            'ids' => $reranked,
            'applied' => true,
            'scores' => $scoreBreakdown,
            'graph_context_counts' => $relationshipScores,
        ];
    }

    /**
     * @return array{ids: array<int|string>, scores: array<string, float>, fallback_reason: ?string}
     */
    private function semanticSearchIds(string $query, string $entityTypeId, int $limit): array
    {
        try {
            $queryVector = $this->embeddingProvider?->embed($query) ?? [];
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'Semantic search provider error; falling back to keyword mode: %s',
                $exception->getMessage(),
            ));
            return ['ids' => [], 'scores' => [], 'fallback_reason' => 'embedding_provider_error'];
        }

        $matches = $this->embeddingStorage->findSimilar($queryVector, $entityTypeId, $limit);

        $ids = [];
        $scores = [];
        foreach ($matches as $match) {
            if (is_array($match) && is_string($match['id'] ?? null) && $match['id'] !== '') {
                $rawId = $match['id'];
                $id = ctype_digit($rawId) ? (int) $rawId : $rawId;
                $ids[] = $id;
                if (is_numeric($match['score'] ?? null)) {
                    $scores[(string) $id] = (float) $match['score'];
                }
            }
        }

        return ['ids' => $ids, 'scores' => $scores, 'fallback_reason' => null];
    }

    /**
     * @param \Waaseyaa\Entity\Storage\EntityStorageInterface $storage
     * @return array<int|string>
     */
    private function keywordSearchIds(EntityStorageInterface $storage, string $query, int $limit): array
    {
        $candidateIds = [];
        foreach (['title', 'name', 'body'] as $field) {
            try {
                $ids = $storage->getQuery()
                    ->condition($field, $query, 'CONTAINS')
                    ->range(0, $limit)
                    ->execute();
            } catch (\Throwable) {
                continue;
            }

            foreach ($ids as $id) {
                if (!in_array($id, $candidateIds, true)) {
                    $candidateIds[] = $id;
                }
                if (count($candidateIds) >= $limit) {
                    break 2;
                }
            }
        }

        return $candidateIds;
    }

    private function isEntitySearchVisible(mixed $entity): bool
    {
        if (!$entity instanceof \Waaseyaa\Entity\EntityInterface) {
            return false;
        }

        if ($entity->getEntityTypeId() !== 'node') {
            return true;
        }

        return $this->workflowVisibility->isNodePublic($entity->toArray());
    }
}
