<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\Test;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Real database failure discriminators shared by both supported drivers. */
abstract class DatabaseEmbeddingStorageContract extends EmbeddingStorageContract
{
    use EmbeddingFreshnessContract;
    use EmbeddingConcurrentSourceContract;

    protected DBALDatabase $database;

    abstract protected function installInsertFailure(): void;
    abstract protected function removeInsertFailure(): void;

    protected function migrateSchema(): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_24_000001_embeddings_schema.php';
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up(new SchemaBuilder($this->database->getConnection()));
    }

    #[Test]
    public function failed_insert_after_delete_rolls_back_the_original_vector(): void
    {
        $this->storage->store('node', '1', [1, 0]);
        $this->installInsertFailure();
        $failure = null;
        try {
            $this->storage->store('node', '1', [0, 1]);
        } catch (Exception $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'The real replacement INSERT must fail after DELETE.');
        self::assertSame([['id' => '1', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'node', 10));
        $this->removeInsertFailure();
        $this->storage->store('node', '1', [0, 1]);
        self::assertSame([['id' => '1', 'score' => 1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
    }

    #[Test]
    public function corrupt_persisted_vectors_refuse_instead_of_disappearing(): void
    {
        $this->storage->store('node', '1', [1, 0]);
        foreach (['not-json', 'null', '[]', '{"x":1}', '["1"]', '[1e999]'] as $payload) {
            $this->database->query('UPDATE embeddings SET vector = ? WHERE entity_type = ? AND entity_id = ?', [$payload, 'node', '1']);
            $failure = null;
            try {
                $this->storage->findSimilar([1, 0], 'node', 10);
            } catch (\UnexpectedValueException $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, 'Persisted corruption must refuse search.');
            self::assertStringContainsString('[AIV-STORAGE-002]', $failure->getMessage());
        }
    }

    #[Test]
    public function missing_schema_refuses_all_operations_then_recovers_on_same_instance(): void
    {
        $this->database->getConnection()->createSchemaManager()->dropTable('embeddings');
        $storage = new DatabaseEmbeddingStorage($this->database);
        foreach ([
            fn() => $storage->store('node', '1', [1, 0]),
            fn() => $storage->findSimilar([1, 0], 'node', 10),
            fn() => $storage->delete('node', '1'),
        ] as $operation) {
            $failure = null;
            try {
                $operation();
            } catch (\RuntimeException $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure);
            self::assertStringContainsString('[AIV-STORAGE-001]', $failure->getMessage());
            self::assertFalse($this->database->schema()->tableExists('embeddings'));
        }
        $this->migrateSchema();
        $storage->store('node', '1', [1, 0]);
        self::assertSame([['id' => '1', 'score' => 1.0]], $storage->findSimilar([1, 0], 'node', 10));
        $storage->delete('node', '1');
        self::assertSame([], $storage->findSimilar([1, 0], 'node', 10));
    }
}
