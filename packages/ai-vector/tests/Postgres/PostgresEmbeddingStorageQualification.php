<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\Tests\Contract\DatabaseEmbeddingStorageContract;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Exact same conformance suite as SQLite, on the hosted real PostgreSQL. */
final class PostgresEmbeddingStorageQualification extends DatabaseEmbeddingStorageContract
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('WAASEYAA_AIV_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('WAASEYAA_AIV_POSTGRES_URL is required by hosted qualification.');
        }
        $this->connection = DriverManager::getConnection(
            new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($url),
        );
        $schema = $this->connection->createSchemaManager();
        if ($schema->tablesExist(['embeddings'])) {
            $schema->dropTable('embeddings');
        }
        if ($schema->tablesExist(['embedding_generations'])) {
            $schema->dropTable('embedding_generations');
        }
        $generations = require dirname(__DIR__, 2) . '/migrations/2026_09_30_000001_embedding_generations.php';
        $generations->up(new SchemaBuilder($this->connection));
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_24_000001_embeddings_schema.php';
        \assert($migration instanceof Migration);
        $migration->up(new SchemaBuilder($this->connection));
        $history = require dirname(__DIR__, 2) . '/migrations/2026_09_30_000002_embedding_index_history.php';
        $history->up(new SchemaBuilder($this->connection));
        $this->database = new DBALDatabase($this->connection);
        $this->storage = new DatabaseEmbeddingStorage($this->database);
    }

    protected function installInsertFailure(): void
    {
        // Test-only constraint, installed after the production migration.
        $this->connection->executeStatement("ALTER TABLE embeddings ADD CONSTRAINT fail_replacement CHECK (vector <> '[0,1]')");
    }

    protected function removeInsertFailure(): void
    {
        $this->connection->executeStatement('ALTER TABLE embeddings DROP CONSTRAINT fail_replacement');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $schema = $this->connection->createSchemaManager();
            if ($schema->tablesExist(['embeddings'])) {
                $schema->dropTable('embeddings');
            }
            if ($schema->tablesExist(['embedding_generations'])) {
                $schema->dropTable('embedding_generations');
            }
            $this->connection->close();
        }
    }
}
