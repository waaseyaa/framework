<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class DatabaseEmbeddingStorageConformanceTest extends DatabaseEmbeddingStorageContract
{
    private \Waaseyaa\Testing\Database\TemporarySqliteDatabase $temporary;

    protected function setUp(): void
    {
        $this->temporary = new \Waaseyaa\Testing\Database\TemporarySqliteDatabase();
        $database = $this->temporary->database();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $this->database = $database;
        RuntimeSchemaMigrations::aiVector($database);
        $this->storage = new DatabaseEmbeddingStorage($database);
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->close();
        $this->temporary->remove();
    }

    protected function installInsertFailure(): void
    {
        // Test-only trigger, installed after the production migration.
        $this->database->getConnection()->executeStatement("CREATE TRIGGER fail_replacement BEFORE INSERT ON embeddings WHEN NEW.vector = '[0,1]' BEGIN SELECT RAISE(ABORT, 'injected replacement failure'); END");
    }

    protected function removeInsertFailure(): void
    {
        $this->database->getConnection()->executeStatement('DROP TRIGGER fail_replacement');
    }
}
