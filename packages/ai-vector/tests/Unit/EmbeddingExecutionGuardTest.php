<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingExecutionGuardInterface;
use Waaseyaa\AI\Vector\EmbeddingExecutor;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class EmbeddingExecutionGuardTest extends TestCase
{
    #[Test]
    public function missing_schema_refuses_without_creating_runtime_tables(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        try {
            new DatabaseEmbeddingExecutionGuard($database)->begin('node', '01');
            self::fail('Missing schema must refuse.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('AIV-EXECUTION-006', $error->getMessage());
            self::assertFalse($database->schema()->tableExists('embedding_generations'));
        }
    }

    #[Test]
    public function source_invalidation_rolls_back_with_the_source_transaction_and_network_stage_refuses_inside_it(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $storage = new DatabaseEmbeddingStorage($database);
        $guard = new DatabaseEmbeddingExecutionGuard($database);
        $token = $guard->begin('node', '01');
        $storage->store('node', '01', [1, 0]);
        $transaction = $database->transaction();
        $guard->sourceChanged('node', '01', $database, $storage);
        self::assertSame([], $storage->findSimilar([1, 0], 'node', 10));
        try {
            $guard->begin('node', '01');
            self::fail('Provider operation must not begin inside a transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('AIV-EXECUTION-004', $error->getMessage());
        }
        $transaction->rollBack();
        self::assertTrue($guard->runIfCurrent('node', '01', $token, static fn() => null));
        self::assertSame([['id' => '01', 'score' => 1.0]], $storage->findSimilar([1, 0], 'node', 10));
    }

    #[Test]
    public function source_invalidation_refuses_a_distinct_database_connection(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $other = DBALDatabase::createSqlite(':memory:');
        self::assertFalse(new DatabaseEmbeddingExecutionGuard($database)->supportsStorage(new DatabaseEmbeddingStorage($other)));
        $this->expectExceptionMessage('AIV-EXECUTION-003');
        new DatabaseEmbeddingExecutionGuard($database)->sourceChanged('node', '01', $other, new DatabaseEmbeddingStorage($other));
    }

    #[Test]
    public function failed_cleanup_is_explicit_and_retains_the_initiating_failure(): void
    {
        $guard = $this->createStub(EmbeddingExecutionGuardInterface::class);
        $guard->method('supportsStorage')->willReturn(true);
        $guard->method('begin')->willReturn('token');
        $guard->method('runIfCurrent')->willThrowException(new \RuntimeException('storage unavailable'));
        $storage = $this->createStub(EmbeddingStorageInterface::class);
        $executor = new EmbeddingExecutor($storage, $guard, EmbeddingIndexPolicy::fromArray([]), null);
        $initiating = new \RuntimeException('repository unavailable');
        try {
            $executor->index('node', '01', static fn() => throw $initiating);
            self::fail('Failed cleanup cannot be reported successful.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('AIV-EXECUTION-007', $error->getMessage());
            self::assertSame($initiating, $error->getPrevious());
        }
    }
}
