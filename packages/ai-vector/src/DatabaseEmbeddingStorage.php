<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Stores embeddings in the `embeddings` table owned by ai-vector's migration
 * (FW-AIV-PERSIST-01), through the framework database layer.
 *
 * It never creates schema. Until the migration has run, writes and deletes
 * are skipped and searches find nothing; each call logs a warning.
 */
final class DatabaseEmbeddingStorage implements EmbeddingStorageInterface
{
    private const string TABLE = 'embeddings';

    private bool $tableKnownPresent = false;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function store(string $entityType, string $id, array $vector): void
    {
        if (!$this->tableReady('store')) {
            return;
        }

        $payload = json_encode(array_map(
            static fn(float|int $value): float => (float) $value,
            $vector,
        ), JSON_THROW_ON_ERROR);

        // Delete then insert in one transaction: portable across drivers,
        // unlike SQLite's INSERT OR REPLACE.
        $transaction = $this->database->transaction();
        try {
            $this->database->delete(self::TABLE)
                ->condition('entity_type', $entityType)
                ->condition('entity_id', $id)
                ->execute();
            $this->database->insert(self::TABLE)
                ->fields(['entity_type', 'entity_id', 'vector', 'updated_at'])
                ->values([
                    'entity_type' => $entityType,
                    'entity_id' => $id,
                    'vector' => $payload,
                    'updated_at' => time(),
                ])
                ->execute();
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
    }

    public function findSimilar(array $queryVector, string $entityType, int $limit): array
    {
        if (!$this->tableReady('search')) {
            return [];
        }

        $query = array_map(
            static fn(float|int $value): float => (float) $value,
            $queryVector,
        );

        $rows = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['entity_id', 'vector'])
            ->condition('entity_type', $entityType)
            ->execute();

        $results = [];
        $dimensionMismatches = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $vector = $this->decodeVector($row['vector'] ?? null);
            if ($vector === null) {
                continue;
            }
            if (count($vector) !== count($query)) {
                $dimensionMismatches++;
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
        if (!$this->tableReady('delete')) {
            return;
        }

        $this->database->delete(self::TABLE)
            ->condition('entity_type', $entityType)
            ->condition('entity_id', $id)
            ->execute();
    }

    /**
     * Read-only presence check. A present table is remembered; an absent one
     * is re-checked on every call, so a migration applied later is picked up.
     */
    private function tableReady(string $operation): bool
    {
        if ($this->tableKnownPresent) {
            return true;
        }

        if ($this->database->schema()->tableExists(self::TABLE)) {
            $this->tableKnownPresent = true;

            return true;
        }

        $this->logger->warning(sprintf(
            'ai-vector %s skipped: the `%s` table does not exist. Run `migrate` to apply the ai-vector migration.',
            $operation,
            self::TABLE,
        ));

        return false;
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
