<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector\Fixtures;

use Waaseyaa\AI\Vector\EmbeddingStorageInterface;

/**
 * In-memory storage a host binds instead of ai-vector's (FW-AIV-COMP-01
 * provider-order tests).
 *
 * @internal Test fixture.
 */
final class HostEmbeddingStorage implements EmbeddingStorageInterface
{
    /** @var array<string, list<float>> */
    private array $vectors = [];

    public function store(string $entityType, string $id, array $vector): void
    {
        $this->vectors[$entityType . ':' . $id] = array_map(static fn(float|int $value): float => (float) $value, $vector);
    }

    public function findSimilar(array $queryVector, string $entityType, int $limit): array
    {
        return [];
    }

    public function delete(string $entityType, string $id): void
    {
        unset($this->vectors[$entityType . ':' . $id]);
    }
}
