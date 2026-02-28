<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

/**
 * Value object representing a stored embedding for an entity.
 */
final readonly class EntityEmbedding
{
    /**
     * @param string $entityTypeId Entity type (e.g. 'node').
     * @param int|string $entityId Entity ID.
     * @param float[] $vector The embedding vector.
     * @param array<string, mixed> $metadata Optional metadata (e.g. title, bundle).
     * @param int $createdAt Unix timestamp.
     */
    public function __construct(
        public string $entityTypeId,
        public int|string $entityId,
        public array $vector,
        public array $metadata = [],
        public int $createdAt = 0,
    ) {}
}
