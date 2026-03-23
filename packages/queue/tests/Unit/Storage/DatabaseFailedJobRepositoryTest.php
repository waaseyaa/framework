<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;

#[CoversClass(DatabaseFailedJobRepository::class)]
final class DatabaseFailedJobRepositoryTest extends TestCase
{
    private DBALDatabase $database;
    private DatabaseFailedJobRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->createTable();
        $this->repository = new DatabaseFailedJobRepository($this->database);
    }

    private function createTable(): void
    {
        $this->database->schema()->createTable('waaseyaa_failed_jobs', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'queue' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'payload' => ['type' => 'text', 'not null' => true],
                'exception' => ['type' => 'text', 'not null' => true],
                'failed_at' => ['type' => 'varchar', 'length' => 50, 'not null' => true],
                'retried_at' => ['type' => 'varchar', 'length' => 50],
            ],
            'primary key' => ['id'],
        ]);
    }

    #[Test]
    public function recordStoresAndReturnsId(): void
    {
        $id = $this->repository->record('default', 'serialized-payload', new \RuntimeException('Something failed'));

        self::assertSame('1', $id);

        $record = $this->repository->find($id);
        self::assertNotNull($record);
        self::assertSame('default', $record['queue']);
        self::assertSame('serialized-payload', $record['payload']);
        self::assertStringContainsString('RuntimeException', $record['exception']);
    }

    #[Test]
    public function allReturnsAllRecords(): void
    {
        $this->repository->record('default', 'payload-1', new \RuntimeException('Error 1'));
        $this->repository->record('high', 'payload-2', new \RuntimeException('Error 2'));

        $all = $this->repository->all();
        self::assertCount(2, $all);
    }

    #[Test]
    public function findReturnsNullForMissingRecord(): void
    {
        self::assertNull($this->repository->find('999'));
    }

    #[Test]
    public function forgetRemovesRecord(): void
    {
        $id = $this->repository->record('default', 'payload', new \RuntimeException('Error'));

        $this->repository->forget($id);

        self::assertNull($this->repository->find($id));
    }

    #[Test]
    public function flushRemovesAllRecords(): void
    {
        $this->repository->record('default', 'payload-1', new \RuntimeException('Error 1'));
        $this->repository->record('default', 'payload-2', new \RuntimeException('Error 2'));

        $this->repository->flush();

        self::assertCount(0, $this->repository->all());
    }

    #[Test]
    public function retryReturnsAndRemovesRecord(): void
    {
        $id = $this->repository->record('default', 'payload', new \RuntimeException('Error'));

        $record = $this->repository->retry($id);

        self::assertNotNull($record);
        self::assertSame('default', $record['queue']);
        self::assertNull($this->repository->find($id));
    }

    #[Test]
    public function retryReturnsNullForMissingRecord(): void
    {
        self::assertNull($this->repository->retry('999'));
    }
}
