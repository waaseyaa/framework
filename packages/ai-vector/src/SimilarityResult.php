<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

/**
 * Value object representing a similarity search result.
 */
final readonly class SimilarityResult
{
    /**
     * @param EntityEmbedding $embedding The matching embedding.
     * @param float $score Similarity score (0.0 to 1.0, higher = more similar).
     */
    public function __construct(
        public EntityEmbedding $embedding,
        public float $score,
    ) {}
}
