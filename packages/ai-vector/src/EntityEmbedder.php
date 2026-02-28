<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

use Aurora\Entity\EntityInterface;

/**
 * Service that generates and manages embeddings for entities.
 */
final class EntityEmbedder
{
    public function __construct(
        private readonly EmbeddingInterface $embedding,
        private readonly VectorStoreInterface $store,
    ) {}

    /**
     * Generate and store an embedding for an entity.
     *
     * Uses the entity label and serialized array data as input text
     * for the embedding provider.
     */
    public function embedEntity(EntityInterface $entity): EntityEmbedding
    {
        $text = $this->buildEntityText($entity);
        $vector = $this->embedding->embed($text);

        $entityEmbedding = new EntityEmbedding(
            entityTypeId: $entity->getEntityTypeId(),
            entityId: $entity->id(),
            vector: $vector,
            metadata: [
                'label' => $entity->label(),
                'bundle' => $entity->bundle(),
            ],
            createdAt: time(),
        );

        $this->store->store($entityEmbedding);

        return $entityEmbedding;
    }

    /**
     * Search for entities similar to a query string.
     *
     * @return SimilarityResult[]
     */
    public function searchSimilar(string $query, int $limit = 10, ?string $entityTypeId = null): array
    {
        $queryVector = $this->embedding->embed($query);

        return $this->store->search($queryVector, $limit, $entityTypeId);
    }

    /**
     * Remove an entity's embedding.
     */
    public function removeEntity(string $entityTypeId, int|string $entityId): void
    {
        $this->store->delete($entityTypeId, $entityId);
    }

    /**
     * Build the text representation of an entity for embedding.
     */
    private function buildEntityText(EntityInterface $entity): string
    {
        $label = $entity->label();
        $data = json_encode($entity->toArray(), JSON_THROW_ON_ERROR);

        return $label . ' ' . $data;
    }
}
