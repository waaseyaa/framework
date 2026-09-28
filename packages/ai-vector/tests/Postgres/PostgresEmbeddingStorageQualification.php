<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Postgres;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(DatabaseEmbeddingStorage::class)]
final class PostgresEmbeddingStorageQualification extends TestCase
{
    #[Test]
    public function store_search_and_delete_run_on_a_real_postgresql_database(): void
    {
        $url = getenv('WAASEYAA_AIV_POSTGRES_URL');
        if (!is_string($url) || $url === '') {
            throw new \LogicException('WAASEYAA_AIV_POSTGRES_URL is required by the hosted PostgreSQL qualification.');
        }

        $connection = DriverManager::getConnection(
            new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($url),
        );
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['embeddings'])) {
            $schema->dropTable('embeddings');
        }

        $database = new DBALDatabase($connection);
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_24_000001_embeddings_schema.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The ai-vector embeddings migration is invalid.');
        }
        $migration->up(new SchemaBuilder($connection));

        try {
            $storage = new DatabaseEmbeddingStorage($database);
            $storage->store('node', 'one', [1.0, 0.0]);
            $storage->store('node', 'two', [0.0, 1.0]);

            self::assertSame('one', $storage->findSimilar([1.0, 0.0], 'node', 1)[0]['id']);

            $storage->delete('node', 'one');
            self::assertSame('two', $storage->findSimilar([1.0, 0.0], 'node', 1)[0]['id']);

            $storage->delete('node', 'two');
            self::assertSame([], $storage->findSimilar([1.0, 0.0], 'node', 1));
        } finally {
            $schema->dropTable('embeddings');
            $connection->close();
        }
    }
}
