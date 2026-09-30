<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\EmbeddingExecutor;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/** Same separate-process lock discriminator on file-backed SQLite and hosted PostgreSQL. */
trait EmbeddingConcurrentSourceContract
{
    private function createConcurrentSource(): void
    {
        $schema = new SchemaBuilder($this->database->getConnection());
        $schema->create('embedding_concurrency_source', static function (TableBuilder $table): void {
            $table->string('id', 255);
            $table->string('title', 255);
            $table->primary(['id']);
        });
        $this->database->query('INSERT INTO embedding_concurrency_source (id, title) VALUES (?, ?)', ['01', 'old']);
    }

    private function sourcePeer(string $action, bool $publish = false): string
    {
        $params = $this->database->getConnection()->getParams();
        if (($params['memory'] ?? false) === true) {
            self::fail('Separate-process qualification requires file-backed SQLite.');
        }
        $peer = new Process(
            [PHP_BINARY, __DIR__ . '/../Support/embedding-source-peer.php', $action, $publish ? 'publish' : 'invalidate'],
            env: ['WAASEYAA_AIV_SOURCE_PEER_PARAMS' => json_encode($params, JSON_THROW_ON_ERROR)],
            timeout: 8,
        );
        try {
            $peer->mustRun();
            $output = $peer->getOutput();
            self::assertStringStartsWith("STARTED\n", $output);
            self::assertSame('', $peer->getErrorOutput());
            return trim(substr($output, strlen("STARTED\n")));
        } finally {
            if ($peer->isRunning()) {
                $peer->stop(0);
            }
        }
    }

    #[Test]
    public function independent_source_writer_cannot_commit_across_locked_publication(): void
    {
        $this->createConcurrentSource();
        try {
            foreach (['update', 'delete', 'unpublish', 'exclude'] as $action) {
                $this->database->query('DELETE FROM embedding_concurrency_source');
                $this->database->query('INSERT INTO embedding_concurrency_source (id, title) VALUES (?, ?)', ['01', 'old']);
                $guard = new DatabaseEmbeddingExecutionGuard($this->database);
                $token = $guard->begin('concurrent_note', '01');
                self::assertTrue($guard->runIfCurrent('concurrent_note', '01', $token, function () use ($action): void {
                    // Deliberately ordinary reads: no entity lock inversion.
                    self::assertSame('old', $this->database->getConnection()->fetchOne('SELECT title FROM embedding_concurrency_source WHERE id = ?', ['01']));
                    self::assertSame('BLOCKED', $this->sourcePeer($action), 'Source commit must join the freshness fence.');
                    $this->storage->store('concurrent_note', '01', [1, 0]);
                }));
                self::assertSame('COMMITTED', $this->sourcePeer($action));
                self::assertSame([], $this->storage->findSimilar([1, 0], 'concurrent_note', 10));
                self::assertFalse($guard->runIfCurrent('concurrent_note', '01', $token, fn() => self::fail('Old publication escaped.')));
                self::assertFalse($guard->runIfCurrent('concurrent_note', '01', $token, fn() => self::fail('Old cleanup escaped.')));
            }
        } finally {
            $this->database->getConnection()->createSchemaManager()->dropTable('embedding_concurrency_source');
        }
    }

    #[Test]
    public function independent_source_commit_during_provider_wait_fences_success_and_failure_cleanup(): void
    {
        $this->createConcurrentSource();
        try {
            foreach (['update', 'delete', 'unpublish', 'exclude'] as $action) {
                foreach ([false, true] as $fail) {
                    $this->database->query('DELETE FROM embedding_concurrency_source');
                    $this->database->query('INSERT INTO embedding_concurrency_source (id, title) VALUES (?, ?)', ['01', 'old']);
                    $this->storage->store('concurrent_note', '01', [1, 0]);
                    $provider = $this->createMock(EmbeddingProviderInterface::class);
                    $provider->expects(self::once())->method('embed')->willReturnCallback(function () use ($action, $fail): array {
                        self::assertFalse($this->database->getConnection()->isTransactionActive(), 'Network stage cannot hold source transaction.');
                        self::assertSame('COMMITTED', $this->sourcePeer($action, $action === 'update'));
                        if ($fail) {
                            throw new \RuntimeException('old provider failed');
                        }
                        return [1, 0];
                    });
                    $policy = EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => [
                        'concurrent_note' => ['fields' => ['title'], 'allow_external' => true],
                    ]]]);
                    $executor = new EmbeddingExecutor($this->storage, new DatabaseEmbeddingExecutionGuard($this->database), $policy, $provider);
                    $load = function (): ?ConcurrentSourceEntity {
                        $title = $this->database->getConnection()->fetchOne('SELECT title FROM embedding_concurrency_source WHERE id = ?', ['01']);
                        return $title === false ? null : new ConcurrentSourceEntity(['id' => '01', 'title' => $title]);
                    };
                    try {
                        self::assertSame('superseded', $executor->index('concurrent_note', '01', $load));
                        self::assertFalse($fail, 'Expected provider failure must propagate.');
                    } catch (\RuntimeException $error) {
                        self::assertTrue($fail);
                        self::assertSame('old provider failed', $error->getMessage());
                    }
                    self::assertSame(
                        $action === 'update' ? [['id' => '01', 'score' => 1.0]] : [],
                        $this->storage->findSimilar([0, 1], 'concurrent_note', 10),
                    );
                }
            }
        } finally {
            $this->database->getConnection()->createSchemaManager()->dropTable('embedding_concurrency_source');
        }
    }
}

#[ContentEntityType(id: 'concurrent_note')]
#[ContentEntityKeys(id: 'id', label: 'title')]
final class ConcurrentSourceEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
}
