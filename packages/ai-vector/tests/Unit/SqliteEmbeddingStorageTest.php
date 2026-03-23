<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;

#[CoversClass(SqliteEmbeddingStorage::class)]
final class SqliteEmbeddingStorageTest extends TestCase
{
    private \PDO $pdo;
    private SqliteEmbeddingStorage $storage;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->storage = new SqliteEmbeddingStorage($this->pdo);
    }

    #[Test]
    public function storesAndFindsSimilarVectors(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0]);
        $this->storage->store('node', '2', [0.0, 1.0]);
        $this->storage->store('node', '3', [0.7, 0.7]);

        $results = $this->storage->findSimilar([1.0, 0.0], 'node', 2);

        $this->assertCount(2, $results);
        $this->assertSame('1', $results[0]['id']);
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
    }

    #[Test]
    public function overwritesExistingEmbeddingForSameEntity(): void
    {
        $this->storage->store('node', '1', [0.0, 1.0]);
        $this->storage->store('node', '1', [1.0, 0.0]);

        $results = $this->storage->findSimilar([1.0, 0.0], 'node', 1);

        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['id']);
        $this->assertGreaterThan(0.99, $results[0]['score']);
    }

    #[Test]
    public function ignoresVectorsWithMismatchedDimension(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0, 0.0]);
        $this->storage->store('node', '2', [0.5, 0.5]);

        $results = $this->storage->findSimilar([1.0, 0.0], 'node', 10);

        $this->assertCount(1, $results);
        $this->assertSame('2', $results[0]['id']);
    }

    #[Test]
    public function logsDimensionMismatchForObservability(): void
    {
        $lines = [];
        $logger = new \Waaseyaa\Foundation\Log\ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $storage = new SqliteEmbeddingStorage($this->pdo, logger: $logger);

        $storage->store('node', '1', [1.0, 0.0, 0.0]);
        $storage->store('node', '2', [0.5, 0.5]);
        $storage->findSimilar([1.0, 0.0], 'node', 10);

        $logContents = implode("\n", $lines);
        $this->assertStringContainsString('Embedding dimension mismatch', $logContents);
    }

    #[Test]
    public function deleteRemovesEmbeddingForEntity(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0]);
        $this->storage->store('node', '2', [0.0, 1.0]);

        $beforeDelete = $this->storage->findSimilar([1.0, 0.0], 'node', 10);
        $this->assertCount(2, $beforeDelete);

        $this->storage->delete('node', '1');

        $afterDelete = $this->storage->findSimilar([1.0, 0.0], 'node', 10);
        $this->assertCount(1, $afterDelete);
        $this->assertSame('2', $afterDelete[0]['id']);
    }

    #[Test]
    public function deleteThenRecreateDoesNotAccumulateStaleRows(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0]);
        $this->storage->delete('node', '1');
        $this->storage->store('node', '1', [0.0, 1.0]);

        $results = $this->storage->findSimilar([0.0, 1.0], 'node', 10);
        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['id']);
        $this->assertGreaterThan(0.99, $results[0]['score']);
    }
}
