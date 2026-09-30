<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class DatabaseEmbeddingStorageConformanceTest extends EmbeddingStorageContract
{
    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $this->storage = new DatabaseEmbeddingStorage($database);
    }
}
