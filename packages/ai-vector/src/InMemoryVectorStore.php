<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

/**
 * In-memory vector store implementation for testing and development.
 *
 * Uses cosine similarity for search operations.
 */
final class InMemoryVectorStore implements VectorStoreInterface
{
    /**
     * Stored embeddings keyed by "{entityTypeId}:{entityId}".
     *
     * @var array<string, EntityEmbedding>
     */
    private array $embeddings = [];

    public function store(EntityEmbedding $embedding): void
    {
        $key = $this->buildKey($embedding->entityTypeId, $embedding->entityId);
        $this->embeddings[$key] = $embedding;
    }

    public function delete(string $entityTypeId, int|string $entityId): void
    {
        $key = $this->buildKey($entityTypeId, $entityId);
        unset($this->embeddings[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function search(array $queryVector, int $limit = 10, ?string $entityTypeId = null): array
    {
        $results = [];

        foreach ($this->embeddings as $embedding) {
            if ($entityTypeId !== null && $embedding->entityTypeId !== $entityTypeId) {
                continue;
            }

            $score = self::cosineSimilarity($queryVector, $embedding->vector);

            $results[] = new SimilarityResult(
                embedding: $embedding,
                score: $score,
            );
        }

        // Sort by score descending.
        usort($results, static fn(SimilarityResult $a, SimilarityResult $b): int =>
            $b->score <=> $a->score
        );

        return array_slice($results, 0, $limit);
    }

    public function get(string $entityTypeId, int|string $entityId): ?EntityEmbedding
    {
        $key = $this->buildKey($entityTypeId, $entityId);
        return $this->embeddings[$key] ?? null;
    }

    public function has(string $entityTypeId, int|string $entityId): bool
    {
        $key = $this->buildKey($entityTypeId, $entityId);
        return isset($this->embeddings[$key]);
    }

    /**
     * Compute cosine similarity between two vectors.
     *
     * Returns a value between -1.0 and 1.0 (typically 0.0 to 1.0 for
     * normalized non-negative vectors). Returns 0.0 for zero vectors.
     *
     * @param float[] $a First vector.
     * @param float[] $b Second vector.
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        // Handle zero vectors gracefully.
        if ($magnitudeA == 0.0 || $magnitudeB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * Build a storage key from entity type and ID.
     */
    private function buildKey(string $entityTypeId, int|string $entityId): string
    {
        return $entityTypeId . ':' . $entityId;
    }
}
