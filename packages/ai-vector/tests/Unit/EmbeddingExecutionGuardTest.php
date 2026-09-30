<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingExecutionGuardInterface;
use Waaseyaa\AI\Vector\EmbeddingExecutor;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingSourceChangedListener;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Event\EntitySourceChangedEvent;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(DatabaseEmbeddingExecutionGuard::class)]
#[CoversClass(DatabaseEmbeddingStorage::class)]
#[CoversClass(EmbeddingExecutor::class)]
#[CoversClass(EmbeddingSourceChangedListener::class)]
#[CoversClass(EntitySourceChangedEvent::class)]
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
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addListener(EntitySourceChangedEvent::class, [new EmbeddingSourceChangedListener($storage, $guard), 'onSourceChanged']);
        $dispatcher->dispatch(new EntitySourceChangedEvent('node', '01', $database));
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
    public function inspection_preserves_inflight_token_and_refuses_an_untracked_identity(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $storage = new DatabaseEmbeddingStorage($database);
        $guard = new DatabaseEmbeddingExecutionGuard($database);
        self::assertFalse($guard->runWithCurrent('node', '01', static fn() => self::fail('Untracked cleanup executed.')));
        $token = $guard->begin('node', '01');
        self::assertTrue($guard->runWithCurrent('node', '01', fn() => $storage->delete('node', '01')));
        self::assertTrue($guard->runIfCurrent('node', '01', $token, fn() => $storage->store('node', '01', [0, 1])));
        self::assertSame([['id' => '01', 'score' => 1.0]], $storage->findSimilar([0, 1], 'node', 10));
    }

    #[Test]
    public function indexing_intent_is_monotonic_and_cleanup_preserves_never_indexed_history(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $storage = new DatabaseEmbeddingStorage($database);
        $guard = new DatabaseEmbeddingExecutionGuard($database, EmbeddingIndexPolicy::fromArray([]));
        $cleanup = $guard->beginForCleanup('unrelated', '01');
        self::assertSame(0, (int) $database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations'));
        self::assertSame('not_indexed', $guard->cleanupOutcomeIfCurrent('unrelated', '01', $cleanup, $storage));
        $indexing = $guard->begin('unrelated', '01');
        self::assertSame(1, (int) $database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations'));
        self::assertFalse($guard->cleanupIfCurrent('unrelated', '01', $cleanup, $storage));
        $guard->beginForCleanup('unrelated', '01');
        self::assertSame(1, (int) $database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations'));
        self::assertFalse($guard->runIfCurrent('unrelated', '01', $indexing, static fn() => self::fail('Old intent published.')));
    }

    #[Test]
    public function canonical_store_promotes_history_preserves_token_and_deletion_never_clears_it(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $storage = new DatabaseEmbeddingStorage($database);
        $guard = new DatabaseEmbeddingExecutionGuard($database, EmbeddingIndexPolicy::fromArray([]));
        $token = $guard->beginForCleanup('unrelated', '01');
        $storage->store('unrelated', '01', [1, 0]);
        self::assertSame(1, (int) $database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations'));
        self::assertSame($token, $database->getConnection()->fetchOne('SELECT token FROM embedding_generations'));
        $storage->delete('unrelated', '01');
        self::assertSame(1, (int) $database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations'));
    }

    #[Test]
    public function corrupt_history_refuses_without_rotating_token_or_replacing_vector(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $storage = new DatabaseEmbeddingStorage($database);
        $guard = new DatabaseEmbeddingExecutionGuard($database, EmbeddingIndexPolicy::fromArray([]));
        $token = $guard->beginForCleanup('unrelated', '01');
        $database->getConnection()->executeStatement('UPDATE embedding_generations SET potentially_indexed = 7');
        try {
            $guard->beginForCleanup('unrelated', '01');
            self::fail('Corrupt authority cannot prove absence.');
        } catch (\UnexpectedValueException $error) {
            self::assertStringContainsString('AIV-EXECUTION-010', $error->getMessage());
        }
        self::assertSame($token, $database->getConnection()->fetchOne('SELECT token FROM embedding_generations'));
        $this->expectExceptionMessage('AIV-EXECUTION-010');
        $storage->store('unrelated', '01', [1, 0]);
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
