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
 * It never creates schema. Missing schema refuses with AIV-STORAGE-001.
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
        VectorMath::identity($entityType, $id);
        VectorMath::validate($vector);
        $this->requireTable('store');

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
            // This table has a natural composite key, not an identity column.
            // The generic InsertInterface returns an identity value, which is
            // not available on PostgreSQL for this shape. Execute the same
            // bound DML through DatabaseInterface's portable statement path.
            $columns = ['entity_type', 'entity_id', 'vector', 'updated_at'];
            $this->database->query(sprintf(
                'INSERT INTO %s (%s) VALUES (?, ?, ?, ?)',
                $this->database->quoteIdentifier(self::TABLE),
                implode(', ', array_map($this->database->quoteIdentifier(...), $columns)),
            ), [$entityType, $id, $payload, time()]);
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
    }

    /** @internal The freshness fence must use this exact database connection object. */
    public function isOnDatabase(DatabaseInterface $database): bool
    {
        return $this->database === $database;
    }

    public function findSimilar(array $queryVector, string $entityType, int $limit): array
    {
        VectorMath::identity($entityType);
        VectorMath::validate($queryVector);
        $this->requireTable('search');

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
            if (count($vector) !== count($query)) {
                $dimensionMismatches++;
                continue;
            }

            $results[] = [
                'id' => (string) ($row['entity_id'] ?? ''),
                'score' => VectorMath::cosine($query, $vector),
            ];
        }

        usort($results, static function (array $a, array $b): int {
            $scoreOrder = $b['score'] <=> $a['score'];
            return $scoreOrder !== 0 ? $scoreOrder : strcmp($a['id'], $b['id']);
        });

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
        VectorMath::identity($entityType, $id);
        $this->requireTable('delete');

        $this->database->delete(self::TABLE)
            ->condition('entity_type', $entityType)
            ->condition('entity_id', $id)
            ->execute();
    }

    /**
     * Read-only presence check. A present table is remembered; an absent one
     * is re-checked on every call, so a migration applied later is picked up.
     */
    private function requireTable(string $operation): void
    {
        if ($this->tableKnownPresent) {
            return;
        }

        if ($this->database->schema()->tableExists(self::TABLE)) {
            $this->tableKnownPresent = true;

            return;
        }

        $this->logger->warning(sprintf(
            'ai-vector %s skipped: the `%s` table does not exist. Run `migrate` to apply the ai-vector migration.',
            $operation,
            self::TABLE,
        ));

        throw new \RuntimeException('[AIV-STORAGE-001] Embeddings schema is unavailable. Run migrate.');
    }

    /**
     * @return non-empty-list<float>
     */
    private function decodeVector(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            throw new \UnexpectedValueException('[AIV-STORAGE-002] Invalid stored embedding.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('[AIV-STORAGE-002] Invalid stored embedding.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('[AIV-STORAGE-002] Invalid stored embedding.');
        }

        try {
            VectorMath::validate($decoded);
        } catch (\InvalidArgumentException $exception) {
            throw new \UnexpectedValueException('[AIV-STORAGE-002] Invalid stored embedding.', previous: $exception);
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \UnexpectedValueException('[AIV-STORAGE-002] Invalid stored embedding.');
            }
            $vector[] = (float) $value;
        }

        return $vector;
    }
}
