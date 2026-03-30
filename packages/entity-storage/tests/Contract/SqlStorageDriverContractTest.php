<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Contract;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;

final class SqlStorageDriverContractTest extends EntityStorageDriverContractTest
{
    private DBALDatabase $database;

    protected function createDriver(): EntityStorageDriverInterface
    {
        $this->database = DBALDatabase::createSqlite();

        $this->database->schema()->createTable('test_entity', [
            'fields' => [
                'id' => ['type' => 'varchar', 'not null' => true],
                'title' => ['type' => 'varchar'],
                'status' => ['type' => 'varchar'],
            ],
            'primary key' => ['id'],
        ]);

        $resolver = new SingleConnectionResolver($this->database);

        return new SqlStorageDriver($resolver, 'id');
    }
}
