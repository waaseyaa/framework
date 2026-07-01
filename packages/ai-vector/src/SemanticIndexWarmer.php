<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Workflows\WorkflowVisibility;

final class SemanticIndexWarmer
{
    public const string CONTRACT_VERSION = 'v1.0';
    public const string CONTRACT_SURFACE = 'semantic_index_warm';
    private const int DEFAULT_CHUNK_SIZE = 200;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider,
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    /**
     * @param list<string> $entityTypeIds
     * @return array{
     *   contract_version: string,
     *   contract_surface: string,
     *   status: string,
     *   requested_entity_types: list<string>,
     *   processed_total: int,
     *   stored_total: int,
     *   removed_total: int,
     *   missing_total: int,
     *   duration_ms: float,
     *   by_type: array<string, array{status: string, candidates: int, processed: int, stored: int, removed: int, missing: int}>
     * }
     */
    public function warm(array $entityTypeIds = ['node'], int $limit = 0): array
    {
        $startedAt = hrtime(true);
        $requestedEntityTypes = $this->normalizeEntityTypeIds($entityTypeIds);

        $report = [
            'contract_version' => self::CONTRACT_VERSION,
            'contract_surface' => self::CONTRACT_SURFACE,
            'status' => 'ok',
            'requested_entity_types' => $requestedEntityTypes,
            'processed_total' => 0,
            'stored_total' => 0,
            'removed_total' => 0,
            'missing_total' => 0,
            'duration_ms' => 0.0,
            'by_type' => [],
        ];

        if ($this->embeddingProvider === null) {
            $report['status'] = 'skipped_no_provider';
            $report['duration_ms'] = $this->durationMs($startedAt);
            return $report;
        }

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
        );

        foreach ($requestedEntityTypes as $entityTypeId) {
            if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
                $report['by_type'][$entityTypeId] = [
                    'status' => 'missing_entity_type',
                    'candidates' => 0,
                    'processed' => 0,
                    'stored' => 0,
                    'removed' => 0,
                    'missing' => 0,
                ];
                $report['status'] = 'completed_with_skips';
                continue;
            }

            $ids = $this->collectSortedIds($entityTypeId, $limit);
            $typeStats = $this->processIdsInChunks($listener, $entityTypeId, $ids);
            $typeProcessed = $typeStats['processed'];
            $typeStored = $typeStats['stored'];
            $typeRemoved = $typeStats['removed'];
            $typeMissing = $typeStats['missing'];

            $report['processed_total'] += $typeProcessed;
            $report['stored_total'] += $typeStored;
            $report['removed_total'] += $typeRemoved;
            $report['missing_total'] += $typeMissing;

            $report['by_type'][$entityTypeId] = [
                'status' => 'ok',
                'candidates' => count($ids),
                'processed' => $typeProcessed,
                'stored' => $typeStored,
                'removed' => $typeRemoved,
                'missing' => $typeMissing,
            ];
        }

        $report['duration_ms'] = $this->durationMs($startedAt);

        return $report;
    }

    /**
     * @param list<string> $entityTypeIds
     * @param array{type_index?: int, offset?: int}|null $cursor
     * @return array{
     *   contract_version: string,
     *   contract_surface: string,
     *   status: string,
     *   requested_entity_types: list<string>,
     *   batch_size: int,
     *   batch_processed: int,
     *   stored_total: int,
     *   removed_total: int,
     *   missing_total: int,
     *   duration_ms: float,
     *   next_cursor: array{type_index: int, offset: int}|null,
     *   by_type: array<string, array{status: string, candidates: int, processed: int, stored: int, removed: int, missing: int}>
     * }
     */
    public function warmBatch(array $entityTypeIds = ['node'], int $batchSize = 200, ?array $cursor = null): array
    {
        $startedAt = hrtime(true);
        $requestedEntityTypes = $this->normalizeEntityTypeIds($entityTypeIds);
        $batchSize = max(1, $batchSize);

        $report = [
            'contract_version' => self::CONTRACT_VERSION,
            'contract_surface' => 'semantic_index_refresh_batch',
            'status' => 'ok',
            'requested_entity_types' => $requestedEntityTypes,
            'batch_size' => $batchSize,
            'batch_processed' => 0,
            'stored_total' => 0,
            'removed_total' => 0,
            'missing_total' => 0,
            'duration_ms' => 0.0,
            'next_cursor' => null,
            'by_type' => [],
        ];

        if ($this->embeddingProvider === null) {
            $report['status'] = 'skipped_no_provider';
            $report['duration_ms'] = $this->durationMs($startedAt);
            return $report;
        }

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
        );

        $cursorTypeIndex = max(0, (int) ($cursor['type_index'] ?? 0));
        $cursorOffset = max(0, (int) ($cursor['offset'] ?? 0));

        for ($typeIndex = $cursorTypeIndex; $typeIndex < count($requestedEntityTypes); $typeIndex++) {
            $entityTypeId = $requestedEntityTypes[$typeIndex];
            $offset = $typeIndex === $cursorTypeIndex ? $cursorOffset : 0;

            if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
                $report['status'] = 'completed_with_skips';
                $report['by_type'][$entityTypeId] = [
                    'status' => 'missing_entity_type',
                    'candidates' => 0,
                    'processed' => 0,
                    'stored' => 0,
                    'removed' => 0,
                    'missing' => 0,
                ];
                continue;
            }

            $ids = $this->collectSortedIds($entityTypeId, 0);
            $remainingCapacity = $batchSize - $report['batch_processed'];
            if ($remainingCapacity <= 0) {
                $report['next_cursor'] = ['type_index' => $typeIndex, 'offset' => $offset];
                break;
            }

            $slice = array_slice($ids, $offset, $remainingCapacity);
            $typeStats = $this->processIdsInChunks($listener, $entityTypeId, $slice);

            $report['batch_processed'] += $typeStats['processed'];
            $report['stored_total'] += $typeStats['stored'];
            $report['removed_total'] += $typeStats['removed'];
            $report['missing_total'] += $typeStats['missing'];
            $report['by_type'][$entityTypeId] = [
                'status' => 'ok',
                'candidates' => count($slice),
                'processed' => $typeStats['processed'],
                'stored' => $typeStats['stored'],
                'removed' => $typeStats['removed'],
                'missing' => $typeStats['missing'],
            ];

            $nextOffset = $offset + count($slice);
            if ($nextOffset < count($ids)) {
                $report['next_cursor'] = ['type_index' => $typeIndex, 'offset' => $nextOffset];
                break;
            }

            if ($typeIndex < (count($requestedEntityTypes) - 1) && $report['batch_processed'] >= $batchSize) {
                $report['next_cursor'] = ['type_index' => $typeIndex + 1, 'offset' => 0];
                break;
            }
        }

        $report['duration_ms'] = $this->durationMs($startedAt);

        return $report;
    }

    private function isIndexable(EntityInterface $entity): bool
    {
        if ($entity->getEntityTypeId() !== 'node') {
            return true;
        }

        return $this->workflowVisibility->isNodePublicForEntity($entity);
    }

    /**
     * @return array{processed: int, stored: int, removed: int, missing: int}
     */
    private function processIdsInChunks(EntityEmbeddingListener $listener, string $entityTypeId, array $ids): array
    {
        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $processed = 0;
        $stored = 0;
        $removed = 0;
        $missing = 0;

        foreach (array_chunk($ids, self::DEFAULT_CHUNK_SIZE) as $chunk) {
            // findMany() returns a plain list; re-key by id to preserve the isset() lookup below.
            $entities = [];
            if ($chunk !== []) {
                foreach ($repository->findMany($chunk) as $loadedEntity) {
                    $entities[$loadedEntity->id()] = $loadedEntity;
                }
            }
            foreach ($chunk as $id) {
                if (!isset($entities[$id])) {
                    $missing++;
                    continue;
                }

                $entity = $entities[$id];
                if (!$entity instanceof EntityInterface) {
                    $missing++;
                    continue;
                }

                $listener->onPostSave(new EntityEvent($entity));
                $processed++;

                if ($this->isIndexable($entity)) {
                    $stored++;
                } else {
                    $removed++;
                }
            }
        }

        return [
            'processed' => $processed,
            'stored' => $stored,
            'removed' => $removed,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<int|string>
     */
    private function collectSortedIds(string $entityTypeId, int $limit): array
    {
        // C-22 WP2: the query builder now lives on the repository.
        // system context: index-warming job needs to see all entities to build the embedding store
        $query = $this->entityTypeManager->getRepository($entityTypeId)->getQuery()->accessCheck(false);
        if ($limit > 0) {
            $query = $query->range(0, $limit);
        }

        $ids = $query->execute();
        usort($ids, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        return $ids;
    }

    /**
     * @param list<string> $entityTypeIds
     * @return list<string>
     */
    private function normalizeEntityTypeIds(array $entityTypeIds): array
    {
        $normalized = [];
        foreach ($entityTypeIds as $entityTypeId) {
            $trimmed = trim($entityTypeId);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return ['node'];
        }

        return $normalized;
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
