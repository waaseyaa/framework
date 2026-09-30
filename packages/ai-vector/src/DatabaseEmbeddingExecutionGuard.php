<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/** @api Durable freshness fence shared by lifecycle, deletion and refresh. */
final class DatabaseEmbeddingExecutionGuard implements EmbeddingExecutionGuardInterface
{
    private const string TABLE = 'embedding_generations';

    public function __construct(private readonly DatabaseInterface $database) {}

    public function supportsStorage(EmbeddingStorageInterface $storage): bool
    {
        return $storage instanceof DatabaseEmbeddingStorage && $storage->isOnDatabase($this->database);
    }

    public function sourceChanged(string $type, string $id, DatabaseInterface $database, EmbeddingStorageInterface $storage): void
    {
        if ($database !== $this->database || !$this->supportsStorage($storage)
            || !$database instanceof DBALDatabase || !$database->getConnection()->isTransactionActive()) {
            throw new \LogicException('[AIV-EXECUTION-003] Source invalidation requires one active, shared database transaction.');
        }
        $this->advance($type, $id);
        $storage->delete($type, $id);
    }

    public function begin(string $type, string $id): string
    {
        if ($this->database instanceof DBALDatabase && $this->database->getConnection()->isTransactionActive()) {
            throw new \LogicException('[AIV-EXECUTION-004] Embedding execution cannot start inside a source transaction.');
        }

        return $this->advance($type, $id);
    }

    private function advance(string $type, string $id): string
    {
        VectorMath::identity($type, $id);
        if (!$this->database->schema()->tableExists(self::TABLE)) {
            throw new \LogicException('[AIV-EXECUTION-006] Embedding generation schema is missing; run migrations before activation.');
        }
        $token = bin2hex(random_bytes(16));
        // Supported database backends are SQLite and PostgreSQL. This atomic
        // upsert locks the same identity that publication subsequently locks.
        $this->database->query(
            'INSERT INTO embedding_generations (entity_type, entity_id, token) VALUES (?, ?, ?) '
            . 'ON CONFLICT (entity_type, entity_id) DO UPDATE SET token = excluded.token',
            [$type, $id, $token],
        );

        return $token;
    }

    public function runWithCurrent(string $type, string $id, \Closure $operation): bool
    {
        VectorMath::identity($type, $id);
        if (!$this->database instanceof DBALDatabase) {
            throw new \LogicException('[AIV-EXECUTION-003] Current-generation inspection requires the shared DBAL database.');
        }
        $transaction = $this->database->transaction();
        try {
            $matched = $this->database->getConnection()->executeStatement(
                'UPDATE embedding_generations SET token = token WHERE entity_type = ? AND entity_id = ?',
                [$type, $id],
            );
            if ($matched === 0) {
                $transaction->commit();
                return false;
            }
            $operation();
            $transaction->commit();
            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    public function runIfCurrent(string $type, string $id, string $token, \Closure $operation): bool
    {
        VectorMath::identity($type, $id);
        $transaction = $this->database->transaction();
        try {
            // A conditional UPDATE obtains writer ownership on SQLite and a
            // row lock on PostgreSQL before rereading content or writing vectors.
            $matched = $this->database->update(self::TABLE)->fields(['token' => $token])
                ->condition('entity_type', $type)->condition('entity_id', $id)
                ->condition('token', $token)->execute();
            if ($matched === 0) {
                $transaction->commit();

                return false;
            }
            $operation();
            $transaction->commit();

            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }
}
