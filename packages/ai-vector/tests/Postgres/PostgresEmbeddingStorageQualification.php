<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\Tests\Contract\EmbeddingStorageContract;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Exact same conformance suite as SQLite, on the hosted real PostgreSQL. */
final class PostgresEmbeddingStorageQualification extends EmbeddingStorageContract
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
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_24_000001_embeddings_schema.php';
        \assert($migration instanceof Migration);
        $migration->up(new SchemaBuilder($this->connection));
        $this->storage = new DatabaseEmbeddingStorage(new DBALDatabase($this->connection));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $schema = $this->connection->createSchemaManager();
            if ($schema->tablesExist(['embeddings'])) {
                $schema->dropTable('embeddings');
            }
            $this->connection->close();
        }
    }
}
