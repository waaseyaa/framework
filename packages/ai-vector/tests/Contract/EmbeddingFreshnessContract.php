<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use PHPUnit\Framework\Attributes\Test;
use Waaseyaa\AI\Vector\DatabaseEmbeddingExecutionGuard;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/** Real migrated storage/guard, synthetic repository and deterministic overlapping provider calls. */
trait EmbeddingFreshnessContract
{
    #[Test]
    public function overlapping_save_delete_exclusion_and_failed_old_calls_cannot_mutate_newer_vectors(): void
    {
        foreach (['save', 'warm', 'warmBatch'] as $entry) {
            foreach (['save', 'delete', 'exclude', 'unpublish', 'failure', 'failure_delete', 'failure_exclude', 'failure_unpublish'] as $race) {
                $served = new FreshnessEntity(['id' => '01', 'title' => 'old', 'status' => 1, 'workflow_state' => 'published']);
                $repository = $this->createStub(EntityRepositoryInterface::class);
                // Capture by reference: mutations represent committed served changes.
                $repository->method('find')->willReturnCallback(static function () use (&$served) {
                    return $served;
                });
                $query = $this->createStub(EntityQueryInterface::class);
                $query->method('accessCheck')->willReturnSelf();
                $query->method('execute')->willReturn(['01']);
                $repository->method('getQuery')->willReturn($query);
                $manager = $this->createStub(EntityTypeManagerInterface::class);
                $manager->method('hasDefinition')->willReturn(true);
                $manager->method('getRepository')->willReturn($repository);
                $policy = EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => [
                    'node' => ['fields' => ['title'], 'allow_external' => true],
                ]]]);
                $guard = new DatabaseEmbeddingExecutionGuard($this->database);
                $newProvider = $this->createStub(EmbeddingSaveProviderInterface::class);
                $newProvider->method('embedForSave')->willReturn([0.0, 1.0]);
                $newListener = new EntityEmbeddingListener(
                    storage: $this->storage,
                    embeddingProvider: $newProvider,
                    entityTypeManager: $manager,
                    indexPolicy: $policy,
                    executionGuard: $guard,
                );
                $oldProvider = $this->createMock(EmbeddingSaveProviderInterface::class);
                $oldProvider->expects(self::once())->method($entry === 'save' ? 'embedForSave' : 'embed')
                    ->willReturnCallback(function () use (&$served, $race, $manager, $newListener, $guard): array {
                        if (str_ends_with($race, 'delete')) {
                            $deleted = $served;
                            $served = null;
                            new EntityEmbeddingCleanupListener($this->storage, executionGuard: $guard, entityTypeManager: $manager)
                                ->onPostDelete(new EntityEvent($deleted));
                        } elseif (str_ends_with($race, 'exclude')) {
                            new EntityEmbeddingListener(
                                storage: $this->storage,
                                entityTypeManager: $manager,
                                executionGuard: $guard,
                            )->onPostSave(new EntityEvent($served));
                        } elseif (str_ends_with($race, 'unpublish')) {
                            $served = new FreshnessEntity(['id' => '01', 'title' => 'new', 'status' => 0, 'workflow_state' => 'draft']);
                            $newListener->onPostSave(new EntityEvent($served));
                        } else {
                            $served = new FreshnessEntity(['id' => '01', 'title' => 'new', 'status' => 1, 'workflow_state' => 'published']);
                            $newListener->onPostSave(new EntityEvent($served));
                        }
                        if (str_starts_with($race, 'failure')) {
                            throw new \RuntimeException('old call failed');
                        }

                        return [1.0, 0.0];
                    });
                $this->storage->store('node', '01', [1.0, 0.0]);
                $failure = null;
                $report = null;
                try {
                    if ($entry === 'save') {
                        new EntityEmbeddingListener(
                            storage: $this->storage,
                            embeddingProvider: $oldProvider,
                            entityTypeManager: $manager,
                            indexPolicy: $policy,
                            executionGuard: $guard,
                        )
                            ->onPostSave(new EntityEvent($served));
                    } else {
                        $report = new SemanticIndexWarmer(
                            $manager,
                            $this->storage,
                            $oldProvider,
                            indexPolicy: $policy,
                            executionGuard: $guard,
                        )->$entry(['node']);
                    }
                } catch (\RuntimeException $error) {
                    $failure = $error;
                }
                if (str_starts_with($race, 'failure') && $entry !== 'save') {
                    self::assertSame('old call failed', $failure?->getMessage());
                    self::assertNull($report);
                } else {
                    self::assertNull($failure);
                    if ($report !== null) {
                        self::assertSame(0, $report['stored_total'], 'Superseded work is never counted as stored.');
                    }
                }
                $hits = $this->storage->findSimilar([0.0, 1.0], 'node', 10);
                self::assertSame(
                    in_array($race, ['delete', 'exclude', 'unpublish', 'failure_delete', 'failure_exclude', 'failure_unpublish'], true) ? [] : [['id' => '01', 'score' => 1.0]],
                    $hits,
                    "$entry/$race: old publication or failure cleanup must not win.",
                );
            }
        }
    }

    #[Test]
    public function delayed_delete_and_invalidation_events_preserve_a_recreated_identity_vector(): void
    {
        $guard = new DatabaseEmbeddingExecutionGuard($this->database);
        $served = new FreshnessEntity(['id' => '01', 'title' => 'recreated', 'status' => 1, 'workflow_state' => 'published']);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($served);
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $guard->begin('node', '01');
        $this->storage->store('node', '01', [0, 1]);
        $old = new EntityEvent(new FreshnessEntity(['id' => '01', 'title' => 'deleted old', 'status' => 1, 'workflow_state' => 'published']));
        new EntityEmbeddingCleanupListener($this->storage, executionGuard: $guard, entityTypeManager: $manager)->onPostDelete($old);
        self::assertSame([['id' => '01', 'score' => 1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
        new EntityEmbeddingListener(storage: $this->storage, executionGuard: $guard, entityTypeManager: $manager, invalidateOnly: true)->onPostSave($old);
        self::assertSame([['id' => '01', 'score' => 1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
    }

    #[Test]
    public function delayed_cleanup_during_recreated_source_indexing_does_not_cancel_publication(): void
    {
        foreach (['save', 'warm', 'warmBatch'] as $entry) {
            $this->storage->delete('node', '01');
            $guard = new DatabaseEmbeddingExecutionGuard($this->database);
            $served = new FreshnessEntity(['id' => '01', 'title' => 'recreated', 'status' => 1, 'workflow_state' => 'published']);
            $repository = $this->createStub(EntityRepositoryInterface::class);
            $repository->method('find')->willReturn($served);
            $query = $this->createStub(EntityQueryInterface::class);
            $query->method('accessCheck')->willReturnSelf();
            $query->method('execute')->willReturn(['01']);
            $repository->method('getQuery')->willReturn($query);
            $manager = $this->createStub(EntityTypeManagerInterface::class);
            $manager->method('hasDefinition')->willReturn(true);
            $manager->method('getRepository')->willReturn($repository);
            $provider = $this->createMock(EmbeddingSaveProviderInterface::class);
            $provider->expects(self::once())->method($entry === 'save' ? 'embedForSave' : 'embed')->willReturnCallback(function () use ($guard, $manager): array {
                new EntityEmbeddingCleanupListener($this->storage, executionGuard: $guard, entityTypeManager: $manager)
                    ->onPostDelete(new EntityEvent(new FreshnessEntity(['id' => '01', 'title' => 'obsolete deleted content'])));
                return [0, 1];
            });
            $policy = EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => ['node' => ['fields' => ['title'], 'allow_external' => true]]]]);
            if ($entry === 'save') {
                new EntityEmbeddingListener(storage: $this->storage, embeddingProvider: $provider, entityTypeManager: $manager, indexPolicy: $policy, executionGuard: $guard)->onPostSave(new EntityEvent($served));
            } else {
                $report = new SemanticIndexWarmer($manager, $this->storage, $provider, indexPolicy: $policy, executionGuard: $guard)->$entry(['node']);
                self::assertSame(1, $report['stored_total'], 'An obsolete cleanup must not supersede in-flight current indexing.');
            }
            self::assertSame([['id' => '01', 'score' => 1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
        }
    }

    #[Test]
    public function generation_tombstones_fence_old_tokens_and_failed_publication_rolls_back(): void
    {
        $guard = new DatabaseEmbeddingExecutionGuard($this->database);
        $other = new DatabaseEmbeddingExecutionGuard($this->database);
        $old = $guard->begin('node', '01');
        $new = $other->begin('node', '01');
        self::assertFalse($guard->runIfCurrent('node', '01', $old, fn() => self::fail('Old token executed.')));
        self::assertTrue($other->runIfCurrent('node', '01', $new, fn() => $this->storage->store('node', '01', [0, 1])));
        try {
            $other->runIfCurrent('node', '01', $new, function (): void {
                $this->storage->delete('node', '01');
                throw new \RuntimeException('publication failed');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('publication failed', $error->getMessage());
        }
        self::assertSame([['id' => '01', 'score' => 1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
    }
}

final readonly class FreshnessEntity implements \Waaseyaa\Entity\EntityInterface
{
    public function __construct(private array $values, private string $type = 'node') {}
    public function id(): int|string|null
    {
        return $this->values['id'] ?? null;
    }
    public function uuid(): string
    {
        return 'synthetic';
    }
    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
    }
    public function getEntityTypeId(): string
    {
        return $this->type;
    }
    public function bundle(): string
    {
        return 'default';
    }
    public function isNew(): bool
    {
        return false;
    }
    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly fixture.');
    }
    public function toArray(): array
    {
        return $this->values;
    }
    public function language(): string
    {
        return 'en';
    }
}
