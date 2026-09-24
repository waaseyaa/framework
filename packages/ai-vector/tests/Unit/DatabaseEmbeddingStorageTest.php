<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(DatabaseEmbeddingStorage::class)]
final class DatabaseEmbeddingStorageTest extends TestCase
{
    private DBALDatabase $database;
    private DatabaseEmbeddingStorage $storage;
    /** @var list<array{LogLevel, string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($this->database);
        $this->storage = new DatabaseEmbeddingStorage($this->database, logger: $this->recordingLogger());
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
    public function searchesOnlyTheRequestedEntityType(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0]);
        $this->storage->store('user', '1', [1.0, 0.0]);

        $results = $this->storage->findSimilar([1.0, 0.0], 'user', 10);

        $this->assertSame([['id' => '1', 'score' => 1.0]], $results);
    }

    #[Test]
    public function overwritesExistingEmbeddingForSameEntity(): void
    {
        $this->storage->store('node', '1', [0.0, 1.0]);
        $this->storage->store('node', '1', [1.0, 0.0]);

        $results = $this->storage->findSimilar([1.0, 0.0], 'node', 10);

        $this->assertCount(1, $results, 'one row per entity');
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
        $this->storage->store('node', '1', [1.0, 0.0, 0.0]);
        $this->storage->store('node', '2', [0.5, 0.5]);
        $this->storage->findSimilar([1.0, 0.0], 'node', 10);

        $this->assertStringContainsString('Embedding dimension mismatch', implode("\n", array_column($this->logged, 1)));
    }

    #[Test]
    public function deleteRemovesEmbeddingForEntity(): void
    {
        $this->storage->store('node', '1', [1.0, 0.0]);
        $this->storage->store('node', '2', [0.0, 1.0]);

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

    #[Test]
    public function withoutTheMigrationItCreatesNothingAndLogs(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $this->logged = [];
        $storage = new DatabaseEmbeddingStorage($database, logger: $this->recordingLogger());
        $tablesBefore = $database->schema()->listTableNames();

        $storage->store('node', '1', [1.0, 0.0]);
        $storage->delete('node', '1');
        $results = $storage->findSimilar([1.0, 0.0], 'node', 10);

        $this->assertSame([], $results);
        $this->assertSame($tablesBefore, $database->schema()->listTableNames(), 'no table is created on the serving path');
        $this->assertFalse($database->schema()->tableExists('embeddings'));
        $this->assertCount(3, $this->logged, 'store, delete and search each report the missing migration');
        foreach ($this->logged as [$level, $message]) {
            $this->assertSame(LogLevel::WARNING, $level);
            $this->assertStringContainsString('embeddings', $message);
            $this->assertStringContainsString('migrate', $message);
        }
    }

    #[Test]
    public function servingPathLeavesTheSchemaManifestUnchanged(): void
    {
        $repository = new MigrationRepository($this->database->getConnection());
        $recorded = $repository->schemaAuthorityManifest()?->schemaFingerprint;
        $this->assertNotNull($recorded);

        $this->storage->store('note', '1', [1.0, 0.0]);
        $this->storage->findSimilar([1.0, 0.0], 'note', 5);
        $this->storage->delete('note', '1');

        $this->assertSame($recorded, $repository->currentLogicalSchemaFingerprint());
    }

    private function recordingLogger(): LoggerInterface
    {
        $logged = &$this->logged;

        return new class ($logged) implements LoggerInterface {
            use LoggerTrait;

            /** @param list<array{LogLevel, string}> $logged */
            public function __construct(private array &$logged) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = [$level, (string) $message];
            }
        };
    }
}
