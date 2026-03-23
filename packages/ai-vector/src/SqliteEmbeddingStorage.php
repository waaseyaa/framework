<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class SqliteEmbeddingStorage implements EmbeddingStorageInterface
{
    private bool $schemaReady = false;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'embeddings',
        ?LoggerInterface $logger = null,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->logger = $logger ?? new NullLogger();
    }

    public function store(string $entityType, string $id, array $vector): void
    {
        $this->ensureSchema();

        $payload = json_encode(array_map(
            static fn(float|int $value): float => (float) $value,
            $vector,
        ), JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT OR REPLACE INTO %s (entity_type, entity_id, vector, updated_at) VALUES (:entity_type, :entity_id, :vector, :updated_at)',
            $this->table,
        ));
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $id,
            ':vector' => $payload,
            ':updated_at' => time(),
        ]);
    }

    public function findSimilar(array $queryVector, string $entityType, int $limit): array
    {
        $this->ensureSchema();

        $query = array_map(
            static fn(float|int $value): float => (float) $value,
            $queryVector,
        );

        $stmt = $this->pdo->prepare(sprintf(
            'SELECT entity_id, vector FROM %s WHERE entity_type = :entity_type',
            $this->table,
        ));
        $stmt->execute([':entity_type' => $entityType]);

        $results = [];
        $dimensionMismatches = 0;
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $vector = $this->decodeVector($row['vector'] ?? null);
            if ($vector === null || count($vector) !== count($query)) {
                if ($vector !== null && count($vector) !== count($query)) {
                    $dimensionMismatches++;
                }
                continue;
            }

            $results[] = [
                'id' => (string) ($row['entity_id'] ?? ''),
                'score' => InMemoryVectorStore::cosineSimilarity($query, $vector),
            ];
        }

        usort($results, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($dimensionMismatches > 0) {
            $this->logger->warning(sprintf(
                'Embedding dimension mismatch: skipped %d row(s) for entity type "%s".',
                $dimensionMismatches,
                $entityType,
            ));
        }

        return array_slice($results, 0, max(0, $limit));
    }

    public function delete(string $entityType, string $id): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE entity_type = :entity_type AND entity_id = :entity_id',
            $this->table,
        ));
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $id,
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                vector TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY(entity_type, entity_id)
            )',
            $this->table,
        ));

        $this->schemaReady = true;
    }

    /**
     * @return list<float>|null
     */
    private function decodeVector(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }
}
