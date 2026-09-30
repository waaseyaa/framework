<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/** @api Durable freshness fence shared by lifecycle, deletion and refresh. */
final class DatabaseEmbeddingExecutionGuard implements EmbeddingExecutionGuardInterface
{
    private const string TABLE = 'embedding_generations';

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?EmbeddingIndexPolicy $policy = null,
    ) {}

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
        $this->advance($type, $id, false);
        $this->deleteWhenRequired($type, $id, $storage);
    }

    public function begin(string $type, string $id): string
    {
        if ($this->database instanceof DBALDatabase && $this->database->getConnection()->isTransactionActive()) {
            throw new \LogicException('[AIV-EXECUTION-004] Embedding execution cannot start inside a source transaction.');
        }

        return $this->advance($type, $id, true);
    }

    /** @internal Cleanup is not indexing intent. */
    public function beginForCleanup(string $type, string $id): string
    {
        if ($this->database instanceof DBALDatabase && $this->database->getConnection()->isTransactionActive()) {
            throw new \LogicException('[AIV-EXECUTION-004] Embedding execution cannot start inside a source transaction.');
        }

        return $this->advance($type, $id, false);
    }

    /** @internal The deletion decision is made under the publication lock. */
    public function cleanupIfCurrent(string $type, string $id, string $token, EmbeddingStorageInterface $storage): bool
    {
        return $this->cleanupOutcomeIfCurrent($type, $id, $token, $storage) !== 'superseded';
    }

    /** @internal Distinguishes projection deletion from authoritative never-indexed proof. */
    public function cleanupOutcomeIfCurrent(string $type, string $id, string $token, EmbeddingStorageInterface $storage): string
    {
        if (!$this->supportsStorage($storage)) {
            throw new \LogicException('[AIV-EXECUTION-003] Cleanup requires the shared storage database.');
        }
        $outcome = 'not_indexed';
        $current = $this->runIfCurrent($type, $id, $token, function () use ($type, $id, $storage, &$outcome): void {
            if ($this->deleteWhenRequired($type, $id, $storage)) {
                $outcome = 'removed';
            }
        });

        return $current ? $outcome : 'superseded';
    }

    private function requireHistorySchema(): void
    {
        if (!$this->database->schema()->tableExists(self::TABLE)
            || !$this->database->schema()->fieldExists(self::TABLE, 'potentially_indexed')) {
            throw new \LogicException('[AIV-EXECUTION-006] Embedding generation history schema is missing; run migrations before activation.');
        }
    }

    private function advance(string $type, string $id, bool $indexing): string
    {
        VectorMath::identity($type, $id);
        $this->requireHistorySchema();
        $token = bin2hex(random_bytes(16));
        $mark = $indexing || $this->policy === null || $this->policy->isDeclared($type);
        $transaction = $this->database->transaction();
        try {
            $this->database->query(
                'INSERT INTO embedding_generations (entity_type, entity_id, token, potentially_indexed) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT (entity_type, entity_id) DO NOTHING',
                [$type, $id, $token, $mark ? 1 : 0],
            );
            $this->database->query(
                'UPDATE embedding_generations SET token = token WHERE entity_type = ? AND entity_id = ?',
                [$type, $id],
            );
            $history = $this->history($type, $id);
            $this->database->query(
                'UPDATE embedding_generations SET token = ?, potentially_indexed = ? WHERE entity_type = ? AND entity_id = ?',
                [$token, $mark || $history ? 1 : 0, $type, $id],
            );
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return $token;
    }

    private function history(string $type, string $id): bool
    {
        $rows = $this->database->query(
            'SELECT potentially_indexed FROM embedding_generations WHERE entity_type = ? AND entity_id = ?',
            [$type, $id],
        );
        foreach ($rows as $row) {
            $value = ((array) $row)['potentially_indexed'] ?? null;
            if ($value === 0 || $value === '0') {
                return false;
            }
            if ($value === 1 || $value === '1') {
                return true;
            }
            throw new \UnexpectedValueException('[AIV-EXECUTION-010] Embedding indexing history is corrupt.');
        }
        throw new \LogicException('[AIV-EXECUTION-009] Embedding indexing history is absent.');
    }

    private function deleteWhenRequired(string $type, string $id, EmbeddingStorageInterface $storage): bool
    {
        $history = $this->history($type, $id);
        if ($this->policy === null || $this->policy->isDeclared($type) || $history) {
            $storage->delete($type, $id);

            return true;
        }

        return false;
    }

    /** @internal Called within canonical replacement's transaction, before projection writes. */
    public static function recordStorageWrite(DatabaseInterface $database, string $type, string $id): void
    {
        // Embeddings-only installations retain their supported storage contract.
        // Old generation schemas remain conservative until the migration backfill.
        if (!$database->schema()->tableExists(self::TABLE)
            || !$database->schema()->fieldExists(self::TABLE, 'potentially_indexed')) {
            return;
        }
        $guard = new self($database);
        $database->query(
            'INSERT INTO embedding_generations (entity_type, entity_id, token, potentially_indexed) VALUES (?, ?, ?, 1) '
            . 'ON CONFLICT (entity_type, entity_id) DO NOTHING',
            [$type, $id, bin2hex(random_bytes(16))],
        );
        $database->query(
            'UPDATE embedding_generations SET token = token WHERE entity_type = ? AND entity_id = ?',
            [$type, $id],
        );
        $guard->history($type, $id);
        $database->query(
            'UPDATE embedding_generations SET potentially_indexed = 1 WHERE entity_type = ? AND entity_id = ?',
            [$type, $id],
        );
    }

    public function runWithCurrent(string $type, string $id, \Closure $operation): bool
    {
        VectorMath::identity($type, $id);
        $this->requireHistorySchema();
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
            $this->history($type, $id);
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
        $this->requireHistorySchema();
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
            $this->history($type, $id);
            $operation();
            $transaction->commit();

            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }
}
