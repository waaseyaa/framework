<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/**
 * In-memory vector store implementation for testing and development.
 *
 * Uses cosine similarity for search operations.
 */
final class InMemoryVectorStore implements VectorStoreInterface
{
    /**
     * Stored embeddings keyed by "{entityTypeId}:{entityId}:{langcode}".
     *
     * @var array<string, EntityEmbedding>
     */
    private array $embeddings = [];

    public function store(EntityEmbedding $embedding): void
    {
        $key = $this->buildKey($embedding->entityTypeId, $embedding->entityId, $embedding->langcode);
        $this->embeddings[$key] = $embedding;
    }

    public function delete(string $entityTypeId, int|string $entityId): void
    {
        // Delete all langcode variants for this entity.
        $prefix = $entityTypeId . ':' . $entityId . ':';
        foreach ($this->embeddings as $key => $embedding) {
            if (\str_starts_with($key, $prefix)) {
                unset($this->embeddings[$key]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function search(
        array $queryVector,
        int $limit = 10,
        ?string $entityTypeId = null,
        ?string $langcode = null,
        array $fallbackLangcodes = [],
    ): array {
        $results = $this->searchForLanguage($queryVector, $entityTypeId, $langcode);

        // If langcode filter was set, results are empty, and we have fallbacks, try them.
        if ($langcode !== null && $results === [] && $fallbackLangcodes !== []) {
            foreach ($fallbackLangcodes as $fallback) {
                $results = $this->searchForLanguage($queryVector, $entityTypeId, $fallback);
                if ($results !== []) {
                    break;
                }
            }
        }

        // Sort by score descending.
        usort(
            $results,
            static fn(SimilarityResult $a, SimilarityResult $b): int =>
            $b->score <=> $a->score,
        );

        return \array_slice($results, 0, $limit);
    }

    public function get(string $entityTypeId, int|string $entityId): ?EntityEmbedding
    {
        // Return the first match for this entity (language-neutral or first langcode).
        $prefix = $entityTypeId . ':' . $entityId . ':';
        foreach ($this->embeddings as $key => $embedding) {
            if (\str_starts_with($key, $prefix)) {
                return $embedding;
            }
        }

        return null;
    }

    public function has(string $entityTypeId, int|string $entityId): bool
    {
        return $this->get($entityTypeId, $entityId) !== null;
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
        if (\count($a) !== \count($b)) {
            throw new \InvalidArgumentException(\sprintf(
                'Vector dimension mismatch: %d vs %d.',
                \count($a),
                \count($b),
            ));
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $count = \count($a);

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
     * Build a storage key from entity type, ID, and langcode.
     */
    private function buildKey(string $entityTypeId, int|string $entityId, string $langcode = ''): string
    {
        return $entityTypeId . ':' . $entityId . ':' . $langcode;
    }

    /**
     * Search embeddings filtered by entity type and optional language.
     *
     * @param float[] $queryVector
     * @return SimilarityResult[]
     */
    private function searchForLanguage(array $queryVector, ?string $entityTypeId, ?string $langcode): array
    {
        $results = [];

        foreach ($this->embeddings as $embedding) {
            if ($entityTypeId !== null && $embedding->entityTypeId !== $entityTypeId) {
                continue;
            }

            if ($langcode !== null && $embedding->langcode !== $langcode) {
                continue;
            }

            $score = self::cosineSimilarity($queryVector, $embedding->vector);

            $results[] = new SimilarityResult(
                embedding: $embedding,
                score: $score,
            );
        }

        return $results;
    }
}
