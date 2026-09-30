<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/** Identical fault and serialization acceptance on SQLite and PostgreSQL. */
trait UnindexedAvailabilityContract
{
    private function availabilitySource(): void
    {
        new SchemaBuilder($this->database->getConnection())->create('embedding_availability_source', static function (TableBuilder $table): void {
            $table->string('id', 255);
            $table->string('title', 255);
            $table->primary(['id']);
        });
    }

    private function availabilityGuard(bool $declared = false): DatabaseEmbeddingExecutionGuard
    {
        return new DatabaseEmbeddingExecutionGuard($this->database, EmbeddingIndexPolicy::fromArray($declared ? ['ai' => ['vector_index' => ['unrelated' => ['fields' => ['title']]]]] : []));
    }

    private function availabilityMutation(DatabaseEmbeddingExecutionGuard $guard, string $id, string $action): void
    {
        $transaction = $this->database->transaction();
        try {
            if ($action === 'delete') {
                $this->database->query('DELETE FROM embedding_availability_source WHERE id = ?', [$id]);
            } else {
                $this->database->query('INSERT INTO embedding_availability_source (id, title) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET title = excluded.title', [$id, $action]);
            }
            $guard->sourceChanged('unrelated', $id, $this->database, $this->storage);
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function availabilityHistory(string $id): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT potentially_indexed FROM embedding_generations WHERE entity_type = ? AND entity_id = ?', ['unrelated', $id]);
    }

    private function availabilityToken(string $id): string
    {
        return (string) $this->database->getConnection()->fetchOne('SELECT token FROM embedding_generations WHERE entity_type = ? AND entity_id = ?', ['unrelated', $id]);
    }

    private function availabilityPeer(string $action, string $id): string
    {
        $params = $this->database->getConnection()->getParams();
        self::assertFalse($params['memory'] ?? false, 'Independent-connection tests require file-backed SQLite.');
        $peer = new Process(
            [PHP_BINARY, __DIR__ . '/../Support/embedding-availability-peer.php', $action, $id],
            env: ['WAASEYAA_AIV_AVAILABILITY_PEER_PARAMS' => json_encode($params, JSON_THROW_ON_ERROR)],
            timeout: 8,
        );
        try {
            $peer->mustRun();
            self::assertStringStartsWith("STARTED\n", $peer->getOutput());
            self::assertSame('', $peer->getErrorOutput());
            return trim(substr($peer->getOutput(), strlen("STARTED\n")));
        } finally {
            if ($peer->isRunning()) {
                $peer->stop(0);
            }
        }
    }

    #[Test]
    public function never_indexed_undeclared_source_commits_during_projection_failure(): void
    {
        $this->availabilitySource();
        $this->database->getConnection()->createSchemaManager()->dropTable('embeddings');
        $guard = new DatabaseEmbeddingExecutionGuard($this->database, EmbeddingIndexPolicy::fromArray([]));
        $transaction = $this->database->transaction();
        try {
            $this->database->query('INSERT INTO embedding_availability_source (id, title) VALUES (?, ?)', ['virgin', 'available']);
            $guard->sourceChanged('unrelated', 'virgin', $this->database, $this->storage);
            $transaction->commit();
            self::assertSame('available', $this->database->getConnection()->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', ['virgin']));
        } catch (\Throwable $error) {
            if ($this->database->getConnection()->isTransactionActive()) {
                $transaction->rollBack();
            }
            throw $error;
        } finally {
            $this->database->getConnection()->createSchemaManager()->dropTable('embedding_availability_source');
        }
        self::assertSame(1, (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM embedding_generations WHERE entity_type = ? AND entity_id = ?', ['unrelated', 'virgin']));
        self::assertSame(0, $this->availabilityHistory('virgin'));
    }

    #[Test]
    public function repeated_undeclared_updates_and_deletion_survive_fault_without_index_history(): void
    {
        $this->availabilitySource();
        $connection = $this->database->getConnection();
        $connection->executeStatement('ALTER TABLE embeddings RENAME TO embeddings_fault');
        try {
            $guard = $this->availabilityGuard();
            foreach (['created', 'updated', 'updated_again', 'delete', 'recreated'] as $action) {
                $old = $this->availabilityToken('virgin');
                $this->availabilityMutation($guard, 'virgin', $action);
                self::assertNotSame($old, $this->availabilityToken('virgin'));
                self::assertSame(0, $this->availabilityHistory('virgin'));
                self::assertSame($action === 'delete' ? false : $action, $connection->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', ['virgin']));
            }
        } finally {
            $connection->executeStatement('ALTER TABLE embeddings_fault RENAME TO embeddings');
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
    }

    #[Test]
    public function declared_history_provider_intent_and_tombstones_fail_closed_with_source_rollback(): void
    {
        $this->availabilitySource();
        $guard = $this->availabilityGuard();
        foreach (['previous', 'tombstone', 'inflight', 'declared'] as $id) {
            $this->availabilityMutation($guard, $id, 'old');
        }
        $this->storage->store('unrelated', 'previous', [1, 0]);
        $this->storage->store('unrelated', 'tombstone', [1, 0]);
        $this->storage->delete('unrelated', 'tombstone');
        $guard->begin('unrelated', 'inflight');
        $tokens = [];
        foreach (['previous', 'tombstone', 'inflight', 'declared'] as $id) {
            $tokens[$id] = $this->availabilityToken($id);
        }
        $connection = $this->database->getConnection();
        $connection->executeStatement('ALTER TABLE embeddings RENAME TO embeddings_fault');
        try {
            foreach (['previous', 'tombstone', 'inflight', 'declared'] as $id) {
                foreach (['new', 'delete'] as $action) {
                    $failure = null;
                    try {
                        $this->availabilityMutation($id === 'declared' ? $this->availabilityGuard(true) : $guard, $id, $action);
                    } catch (\Throwable $error) {
                        $failure = $error;
                    }
                    self::assertNotNull($failure, 'Potential or configured indexing must retain transactional refusal.');
                    self::assertSame('old', $connection->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', [$id]));
                    self::assertSame($tokens[$id], $this->availabilityToken($id), 'Failed invalidation cannot rotate the committed generation.');
                    self::assertSame($id === 'declared' ? 0 : 1, $this->availabilityHistory($id));
                }
            }
        } finally {
            $connection->executeStatement('ALTER TABLE embeddings_fault RENAME TO embeddings');
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
        self::assertSame([['id' => 'previous', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'unrelated', 10));
        $this->availabilitySource();
        try {
            $this->availabilityMutation($guard, 'previous', 'recovered');
            self::assertSame([], $this->storage->findSimilar([1, 0], 'unrelated', 10));
            self::assertSame(1, $this->availabilityHistory('previous'));
        } finally {
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
    }

    #[Test]
    public function direct_replacement_promotes_history_atomically_and_preserves_generation(): void
    {
        $guard = $this->availabilityGuard();
        $token = $guard->beginForCleanup('unrelated', 'direct');
        self::assertSame(0, $this->availabilityHistory('direct'));
        $this->installInsertFailure();
        try {
            $failure = null;
            try {
                $this->storage->store('unrelated', 'direct', [0, 1]);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            self::assertNotNull($failure);
            self::assertSame(0, $this->availabilityHistory('direct'), 'Failed replacement cannot manufacture history.');
            self::assertSame($token, $this->availabilityToken('direct'));
        } finally {
            $this->removeInsertFailure();
        }
        $this->storage->store('unrelated', 'direct', [1, 0]);
        self::assertSame(1, $this->availabilityHistory('direct'));
        self::assertSame($token, $this->availabilityToken('direct'));
        $this->storage->delete('unrelated', 'direct');
        self::assertSame(1, $this->availabilityHistory('direct'), 'Deletion must retain history tombstones.');
        $this->storage->store('unrelated', 'absent', [1, 0]);
        self::assertSame(1, $this->availabilityHistory('absent'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $this->availabilityToken('absent'));
    }

    #[Test]
    public function undeclared_cleanup_does_not_manufacture_history_or_claim_storage_during_fault(): void
    {
        $guard = $this->availabilityGuard();
        $token = $guard->beginForCleanup('unrelated', 'clean');
        $connection = $this->database->getConnection();
        $connection->executeStatement('ALTER TABLE embeddings RENAME TO embeddings_fault');
        try {
            self::assertTrue($guard->cleanupIfCurrent('unrelated', 'clean', $token, $this->storage));
            self::assertSame(0, $this->availabilityHistory('clean'));
            $new = $guard->beginForCleanup('unrelated', 'clean');
            self::assertNotSame($token, $new);
            self::assertFalse($guard->cleanupIfCurrent('unrelated', 'clean', $token, $this->storage));
        } finally {
            $connection->executeStatement('ALTER TABLE embeddings_fault RENAME TO embeddings');
        }
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn(new FreshnessEntity(['id' => 'clean', 'title' => 'available'], 'unrelated'));
        $query = $this->createStub(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('execute')->willReturn(['clean']);
        $repository->method('getQuery')->willReturn($query);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturn(true);
        $manager->method('getRepository')->willReturn($repository);
        $warmer = new SemanticIndexWarmer($manager, $this->storage, null, indexPolicy: EmbeddingIndexPolicy::fromArray([]), executionGuard: $guard);
        $report = $warmer->warm(['unrelated']);
        self::assertSame('ok', $report['status']);
        self::assertSame(1, $report['processed_total']);
        self::assertSame(0, $report['stored_total']);
        self::assertSame(0, $report['removed_total'], 'A proven never-indexed identity is not a storage deletion.');
        self::assertSame(0, $this->availabilityHistory('clean'));
        $connection->executeStatement('ALTER TABLE embeddings RENAME TO embeddings_fault');
        try {
            foreach (['warm', 'warmBatch'] as $method) {
                $report = $warmer->$method(['unrelated']);
                self::assertSame('ok', $report['status']);
                self::assertSame(1, $report[$method === 'warmBatch' ? 'batch_processed' : 'processed_total']);
                self::assertSame(0, $report['stored_total']);
                self::assertSame(0, $report['removed_total'], 'Outage cannot manufacture a confirmed deletion count.');
                self::assertSame(0, $this->availabilityHistory('clean'));
            }
        } finally {
            $connection->executeStatement('ALTER TABLE embeddings_fault RENAME TO embeddings');
        }
    }

    #[Test]
    public function missing_and_corrupt_authority_refuse_and_rollback_unrelated_source(): void
    {
        $this->availabilitySource();
        $guard = $this->availabilityGuard();
        $this->availabilityMutation($guard, 'corrupt', 'old');
        $connection = $this->database->getConnection();
        $connection->executeStatement('UPDATE embedding_generations SET potentially_indexed = 2 WHERE entity_type = ? AND entity_id = ?', ['unrelated', 'corrupt']);
        try {
            $failure = null;
            try {
                $this->availabilityMutation($guard, 'corrupt', 'new');
            } catch (\Throwable $error) {
                $failure = $error;
            }
            self::assertNotNull($failure);
            self::assertStringContainsString('[AIV-EXECUTION-010]', $failure->getMessage());
            self::assertSame('old', $connection->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', ['corrupt']));
            $connection->createSchemaManager()->dropTable('embedding_generations');
            $failure = null;
            try {
                $this->availabilityMutation($guard, 'missing', 'new');
            } catch (\Throwable $error) {
                $failure = $error;
            }
            self::assertNotNull($failure);
            self::assertStringContainsString('[AIV-EXECUTION-006]', $failure->getMessage());
            self::assertFalse($connection->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', ['missing']));
        } finally {
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
    }

    #[Test]
    public function legacy_upgrade_backfills_vectors_and_conservatively_retains_existing_tombstones(): void
    {
        $connection = $this->database->getConnection();
        $connection->createSchemaManager()->dropTable('embedding_generations');
        $this->storage->store('unrelated', 'legacy', [1, 0]);
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_30_000001_embedding_generations.php';
        $migration->up(new SchemaBuilder($connection));
        $token = str_repeat('a', 32);
        $connection->executeStatement('INSERT INTO embedding_generations (entity_type, entity_id, token) VALUES (?, ?, ?)', ['unrelated', 'tombstone', $token]);
        $failure = null;
        try {
            $this->availabilityGuard()->beginForCleanup('unrelated', 'uncertain');
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertNotNull($failure, 'Execution cannot activate against the old authority schema.');
        self::assertStringContainsString('[AIV-EXECUTION-006]', $failure->getMessage());
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_30_000002_embedding_index_history.php';
        $migration->up(new SchemaBuilder($connection));
        self::assertSame(1, $this->availabilityHistory('legacy'));
        self::assertSame(1, $this->availabilityHistory('tombstone'));
        self::assertSame($token, $this->availabilityToken('tombstone'));
        self::assertSame([['id' => 'legacy', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'unrelated', 10));
        $migration->up(new SchemaBuilder($connection));
        self::assertSame($token, $this->availabilityToken('tombstone'));
    }

    #[Test]
    public function embeddings_only_installation_retains_canonical_storage_without_execution(): void
    {
        $this->database->getConnection()->createSchemaManager()->dropTable('embedding_generations');
        $storage = new DatabaseEmbeddingStorage($this->database);
        $storage->store('unrelated', 'standalone', [1, 0]);
        $storage->store('unrelated', 'standalone', [0, 1]);
        self::assertSame([['id' => 'standalone', 'score' => 1.0]], $storage->findSimilar([0, 1], 'unrelated', 10));
        $storage->delete('unrelated', 'standalone');
        $storage->delete('unrelated', 'standalone');
        self::assertSame([], $storage->findSimilar([0, 1], 'unrelated', 10));
        self::assertFalse($this->database->schema()->tableExists('embedding_generations'), 'Storage must not create execution schema.');
    }

    #[Test]
    public function corrupt_history_blocks_replacement_without_deleting_the_existing_vector(): void
    {
        $this->storage->store('unrelated', 'corrupt', [1, 0]);
        $connection = $this->database->getConnection();
        $token = $this->availabilityToken('corrupt');
        $connection->executeStatement('UPDATE embedding_generations SET potentially_indexed = -1 WHERE entity_type = ? AND entity_id = ?', ['unrelated', 'corrupt']);
        $failure = null;
        try {
            $this->storage->store('unrelated', 'corrupt', [0, 1]);
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('[AIV-EXECUTION-010]', $failure->getMessage());
        self::assertSame($token, $this->availabilityToken('corrupt'));
        self::assertSame(-1, $this->availabilityHistory('corrupt'));
        self::assertSame([['id' => 'corrupt', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'unrelated', 10));
    }

    #[Test]
    public function independent_intent_and_direct_store_serialize_after_unrelated_source_for_absent_and_false_identities(): void
    {
        $this->availabilitySource();
        $guard = $this->availabilityGuard();
        $connection = $this->database->getConnection();
        try {
            foreach (['absent', 'false'] as $state) {
                foreach (['begin', 'store'] as $action) {
                    $id = $state . '_' . $action;
                    if ($state === 'false') {
                        $this->availabilityMutation($guard, $id, 'initial');
                    }
                    $transaction = $this->database->transaction();
                    try {
                        $this->database->query('INSERT INTO embedding_availability_source (id, title) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET title = excluded.title', [$id, 'source_first']);
                        $guard->sourceChanged('unrelated', $id, $this->database, $this->storage);
                        self::assertSame(0, $this->availabilityHistory($id));
                        $token = $this->availabilityToken($id);
                        self::assertSame('BLOCKED', $this->availabilityPeer($action, $id), 'Index intent and direct store must join the source lock even when history is false.');
                        self::assertSame(0, $this->availabilityHistory($id));
                        $transaction->commit();
                    } catch (\Throwable $error) {
                        $transaction->rollBack();
                        throw $error;
                    }
                    self::assertSame('COMMITTED', $this->availabilityPeer($action, $id));
                    self::assertSame(1, $this->availabilityHistory($id));
                    if ($action === 'store') {
                        self::assertSame($token, $this->availabilityToken($id), 'Direct store preserves source generation.');
                        self::assertSame('[1,0]', $connection->fetchOne('SELECT vector FROM embeddings WHERE entity_type = ? AND entity_id = ?', ['unrelated', $id]));
                    } else {
                        self::assertNotSame($token, $this->availabilityToken($id));
                    }
                }
            }
        } finally {
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
    }

    #[Test]
    public function independent_unrelated_source_serializes_after_intent_and_direct_store_for_absent_and_false_identities(): void
    {
        $this->availabilitySource();
        $guard = $this->availabilityGuard();
        $connection = $this->database->getConnection();
        try {
            foreach (['absent', 'false'] as $state) {
                foreach (['begin', 'store'] as $action) {
                    $id = $state . '_' . $action;
                    if ($state === 'false') {
                        $this->availabilityMutation($guard, $id, 'initial');
                    }
                    if ($action === 'begin') {
                        $token = $guard->begin('unrelated', $id);
                        self::assertTrue($guard->runIfCurrent('unrelated', $id, $token, function () use ($id): void {
                            self::assertSame('BLOCKED', $this->availabilityPeer('source', $id));
                        }));
                    } else {
                        $transaction = $this->database->transaction();
                        try {
                            $this->storage->store('unrelated', $id, [1, 0]);
                            self::assertSame('BLOCKED', $this->availabilityPeer('source', $id));
                            $transaction->commit();
                        } catch (\Throwable $error) {
                            $transaction->rollBack();
                            throw $error;
                        }
                    }
                    self::assertSame($state === 'false' ? 'initial' : false, $connection->fetchOne('SELECT title FROM embedding_availability_source WHERE id = ?', [$id]), 'Blocked source changes must be rolled back.');
                    $token = $this->availabilityToken($id);
                    self::assertSame(1, $this->availabilityHistory($id));
                    self::assertSame('COMMITTED', $this->availabilityPeer('source', $id));
                    self::assertNotSame($token, $this->availabilityToken($id));
                    self::assertSame(1, $this->availabilityHistory($id));
                    self::assertFalse($connection->fetchOne('SELECT vector FROM embeddings WHERE entity_type = ? AND entity_id = ?', ['unrelated', $id]));
                    self::assertFalse($guard->runIfCurrent('unrelated', $id, $token, fn() => self::fail('Stale publication escaped the source fence.')));
                    self::assertFalse($guard->cleanupIfCurrent('unrelated', $id, $token, $this->storage), 'Old failure cleanup must stay fenced.');
                }
            }
        } finally {
            $connection->createSchemaManager()->dropTable('embedding_availability_source');
        }
    }
}
