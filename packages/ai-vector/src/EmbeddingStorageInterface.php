<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/**
 * Canonical vector storage contract. See docs/specs/semantic-search-contract.md.
 *
 * Exactly one vector per (entity type, exact string ID); no language variants
 * or persisted metadata. Implementations must pass EmbeddingStorageContract.
 * @api
 */
interface EmbeddingStorageInterface
{
    /**
     * Atomically replace a vector. Invalid input refuses before any mutation.
     * @param non-empty-list<float|int> $vector Finite components; zero is valid.
     * @throws \InvalidArgumentException Invalid vector or empty identity.
     * @throws \RuntimeException Storage unavailable; never a successful no-op.
     */
    public function store(string $entityType, string $id, array $vector): void;

    /**
     * Filter by exact type and matching dimensions. Scores are cosine in
     * [-1,1], zero for zero vectors; descending score then bytewise string ID.
     * Nonpositive limits return []; invalid input still refuses.
     * @param non-empty-list<float|int> $queryVector Finite components.
     * @return list<array{id: string, score: float}>
     */
    public function findSimilar(array $queryVector, string $entityType, int $limit): array;

    /** Idempotent deletion; backend failures propagate, missing IDs do not. */
    public function delete(string $entityType, string $id): void;
}
