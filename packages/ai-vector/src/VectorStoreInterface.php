<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

/**
 * Interface for vector storage backends.
 *
 * Implementations may use in-memory storage, pgvector, Pinecone, Qdrant, etc.
 */
interface VectorStoreInterface
{
    /**
     * Store an entity embedding.
     *
     * If an embedding already exists for the same entity type and ID,
     * it will be overwritten.
     */
    public function store(EntityEmbedding $embedding): void;

    /**
     * Remove an entity's embedding.
     */
    public function delete(string $entityTypeId, int|string $entityId): void;

    /**
     * Find the most similar embeddings to a query vector.
     *
     * @param float[] $queryVector The vector to search against.
     * @param int $limit Maximum number of results to return.
     * @param string|null $entityTypeId Optional entity type filter.
     * @return SimilarityResult[] Results sorted by score descending.
     */
    public function search(array $queryVector, int $limit = 10, ?string $entityTypeId = null): array;

    /**
     * Get a stored embedding for a specific entity.
     */
    public function get(string $entityTypeId, int|string $entityId): ?EntityEmbedding;

    /**
     * Check if an embedding exists for an entity.
     */
    public function has(string $entityTypeId, int|string $entityId): bool;
}
