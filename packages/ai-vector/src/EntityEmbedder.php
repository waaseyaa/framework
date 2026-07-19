<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Service that generates and manages embeddings for entities.
 * @api
 */
final class EntityEmbedder
{
    public function __construct(
        private readonly EmbeddingInterface $embedding,
        private readonly VectorStoreInterface $store,
        private readonly EntityAccessHandler $accessHandler,
        private readonly EntityTypeManagerInterface $entityTypeManager,
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
     * Returns only results that `$account` is permitted to view (fail
     * closed): each candidate is loaded through the entity type's
     * repository and checked with `EntityAccessHandler::check(..., 'view',
     * $account)`. A candidate whose entity type is unregistered, whose
     * entity has been deleted (load returns null), or whose view check does
     * not resolve to Allowed is dropped silently.
     *
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account
     * @return SimilarityResult[]
     */
    public function searchSimilar(string $query, AccountInterface $account, int $limit = 10, ?string $entityTypeId = null): array
    {
        $queryVector = $this->embedding->embed($query);

        $results = $this->store->search($queryVector, $limit, $entityTypeId);

        return array_values(array_filter(
            $results,
            fn(SimilarityResult $result): bool => $this->isViewable($result, $account),
        ));
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    private function isViewable(SimilarityResult $result, AccountInterface $account): bool
    {
        $entityTypeId = $result->embedding->entityTypeId;
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return false;
        }

        $entity = $this->entityTypeManager->getRepository($entityTypeId)->find((string) $result->embedding->entityId);
        if ($entity === null) {
            return false;
        }

        return $this->accessHandler->check($entity, 'view', $account)->isAllowed();
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
        $fieldNames = EntityValues::ordinaryFieldNames($entity);
        if ($entity instanceof EntityBase) {
            $fieldNames = array_values(array_filter(
                $fieldNames,
                static fn(string $field): bool => $entity->fieldReadLevel($field) === FieldReadLevel::Public,
            ));
        }
        $values = [];
        foreach (EntityValues::toCastAwareMap($entity, $fieldNames) as $field => $value) {
            $values[$field] = EntityValues::normalizeValueForJson($value);
        }
        $data = json_encode($values, JSON_THROW_ON_ERROR);

        return $label . ' ' . $data;
    }
}
