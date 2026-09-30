<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;

#[CoversClass(EntityEmbeddingListener::class)]
final class EntityEmbeddingListenerTest extends TestCase
{
    #[Test]
    public function an_unbudgeted_custom_provider_refuses_without_mutating_a_newer_vector(): void
    {
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('delete');
        $storage->expects(self::never())->method('store');
        $guard = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingExecutionGuardInterface::class);
        $guard->method('supportsStorage')->willReturn(true);
        $guard->expects(self::never())->method('begin');
        $logger = new EmbeddingListenerRecordingLogger();
        new EntityEmbeddingListener(storage: $storage, embeddingProvider: $provider, logger: $logger, executionGuard: $guard)
            ->onPostSave(new EntityEvent(new TestEmbeddingEntity(42, 'node')));
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('AIV-EXECUTION-002', $logger->errors[0]);
    }

    #[Test]
    public function a_save_without_fresh_repository_composition_never_indexes_event_content(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('store');
        $storage->expects(self::never())->method('delete');
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects(self::never())->method('embedForSave');
        $logger = new EmbeddingListenerRecordingLogger();
        $listener = new EntityEmbeddingListener(storage: $storage, embeddingProvider: $provider, logger: $logger, indexPolicy: $this->nodePolicy(), executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(42, 'node', ['title' => 'Event must not be trusted', 'status' => 1, 'workflow_state' => 'published'])));
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('AIV-EXECUTION-005', $logger->errors[0]);
    }

    #[Test]
    public function failed_saved_indexing_invalidates_the_old_vector_without_failing_the_committed_save(): void
    {
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects(self::once())->method('embedForSave')->willThrowException(new \RuntimeException('provider unavailable'));
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('store');
        $storage->expects(self::once())->method('delete')->with('node', '42');
        $listener = new EntityEmbeddingListener(storage: $storage, entityTypeManager: $this->entityTypeManager(new TestEmbeddingEntity(42, 'node', ['title' => 'Published', 'status' => 1, 'workflow_state' => 'published'])), embeddingProvider: $provider, indexPolicy: $this->nodePolicy(), executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['title' => 'Published', 'status' => 1, 'workflow_state' => 'published'],
        )));
    }

    #[Test]
    public function removesEmbeddingForUnpublishedNodeWhenStorageAvailable(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('delete')
            ->with('node', '42');

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            entityTypeManager: $this->entityTypeManager(new TestEmbeddingEntity(42, 'node', ['status' => 0, 'workflow_state' => 'archived'])),
            embeddingProvider: null,
            indexPolicy: $this->nodePolicy(),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 0, 'workflow_state' => 'archived'],
        )));
    }

    #[Test]
    public function storesEmbeddingForPublishedNodeWhenProviderAndStorageAvailable(): void
    {
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())
            ->method('embedForSave')
            ->with($this->stringContains('Vector Title'))
            ->willReturn([0.1, 0.2, 0.3]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->with('node', '42', [0.1, 0.2, 0.3]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            entityTypeManager: $this->entityTypeManager(new TestEmbeddingEntity(42, 'node', ['title' => 'Vector Title', 'body' => 'Vector Body', 'status' => 1, 'workflow_state' => 'published'])),
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: [
                'status' => 1,
                'workflow_state' => 'published',
                'title' => 'Vector Title',
                'body' => 'Vector Body',
            ],
        )));
    }

    #[Test]
    public function undeclared_user_like_entity_is_never_embedded_and_its_vector_is_removed(): void
    {
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->never())->method('embedForSave');
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('store');
        $storage->expects($this->once())->method('delete')->with('user', '7');

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            entityTypeManager: $this->entityTypeManager(new TestEmbeddingEntity(7, 'user', ['name' => 'Private person', 'email' => 'private@example.test'])),
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 7,
            entityTypeId: 'user',
            values: ['name' => 'Private person', 'email' => 'private@example.test'],
        )));
    }

    // ------------------------------------------------------------------
    // CW-v1 option-1 (#1920 PR-2, design §3.3): re-source from find(),
    // the de-index bug pin, and the RevisionPointerMovedEvent /
    // REVISION_REVERTED subscriptions.
    // ------------------------------------------------------------------

    #[Test]
    public function de_index_bug_pin_editing_published_content_into_a_forward_draft_does_not_delete_the_embedding(): void
    {
        // The documented WP-2 gap (docs/specs/content-workflow.md
        // "Visibility (read side)"): the in-memory tip says
        // workflow_state='draft' while the SERVED (base row) content is
        // still 'published'/status=1. Re-sourcing via find() must index
        // the served content, never delete the embedding.
        $servedEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Still live',
        ]);
        $draftTip = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'draft',
            'title' => 'Unreviewed forward draft',
        ]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('delete');

        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())->method('embedForSave')->with($this->stringContains('Still live'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            entityTypeManager: $this->entityTypeManager($servedEntity),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent($draftTip));
    }

    #[Test]
    public function draft_save_leaves_the_embedding_at_the_published_content(): void
    {
        $publishedEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Published title',
        ]);
        $draftTip = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'draft',
            'title' => 'Draft title (must not be embedded)',
        ]);

        $storage = $this->createStub(EmbeddingStorageInterface::class);
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())->method('embedForSave')->with($this->logicalAnd(
            $this->stringContains('Published title'),
            $this->logicalNot($this->stringContains('Draft title')),
        ))->willReturn([0.1]);
        $storage->method('store');

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            entityTypeManager: $this->entityTypeManager($publishedEntity),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent($draftTip));
    }

    #[Test]
    public function promotion_reindexes_the_new_live_content(): void
    {
        $promotedEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Promoted content',
        ]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())->method('embedForSave')->with($this->stringContains('Promoted content'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            entityTypeManager: $this->entityTypeManager($promotedEntity),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onPostSave(new EntityEvent($promotedEntity));
    }

    #[Test]
    public function a_standalone_pointer_move_reindexes_via_find(): void
    {
        $servedEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Rolled forward',
        ]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())->method('embedForSave')->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            entityTypeManager: $this->entityTypeManager($servedEntity),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '42',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
        ));
    }

    #[Test]
    public function a_revision_reverted_event_reindexes_via_find(): void
    {
        $servedEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Rolled back',
        ]);
        $eventEntity = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: [
            'status' => 0,
            'workflow_state' => 'draft',
            'title' => 'Whatever revision the event happened to carry',
        ]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->once())->method('embedForSave')->with($this->stringContains('Rolled back'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            indexPolicy: $this->nodePolicy(),
            entityTypeManager: $this->entityTypeManager($servedEntity),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );
        $listener->onRevisionReverted(new EntityEvent($eventEntity));
    }

    #[Test]
    public function a_pointer_move_without_fresh_repository_composition_refuses_without_mutating(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('store');
        $storage->expects($this->never())->method('delete');
        $logger = new EmbeddingListenerRecordingLogger();

        $listener = new EntityEmbeddingListener(storage: $storage, logger: $logger, embeddingProvider: $this->createStub(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class), executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '42',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
        ));
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('AIV-EXECUTION-005', $logger->errors[0]);
    }

    #[Test]
    public function retired_invalidate_only_mode_refuses_without_deleting_newer_vectors(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('delete');
        $storage->expects($this->never())->method('store');
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects($this->never())->method('embedForSave');

        $logger = new EmbeddingListenerRecordingLogger();
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $listener = new EntityEmbeddingListener(storage: $storage, logger: $logger, embeddingProvider: $provider, entityTypeManager: $manager, invalidateOnly: true, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $published = new TestEmbeddingEntity(id: 42, entityTypeId: 'node', values: ['status' => 1, 'workflow_state' => 'published', 'title' => 'Indexable']);
        $listener->onPostSave(new EntityEvent($published));
        $listener->onRevisionReverted(new EntityEvent($published));
        $listener->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '42',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
        ));
        self::assertCount(3, $logger->errors);
        foreach ($logger->errors as $error) {
            self::assertStringContainsString('AIV-EXECUTION-008', $error);
        }
    }

    #[Test]
    public function a_failed_removal_after_commit_is_logged_not_thrown(): void
    {
        $storage = $this->createStub(EmbeddingStorageInterface::class);
        $storage->method('delete')->willThrowException(new \RuntimeException('storage offline'));
        $logger = new EmbeddingListenerRecordingLogger();

        $listener = new EntityEmbeddingListener(storage: $storage, entityTypeManager: $this->entityTypeManager(new TestEmbeddingEntity(42, 'node', ['status' => 0, 'workflow_state' => 'archived'])), logger: $logger, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 0, 'workflow_state' => 'archived'],
        )));

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('AIV-EXECUTION-007', $logger->errors[0]);
    }

    #[Test]
    public function a_failed_re_sourcing_read_is_logged_and_the_vector_removed(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())->method('delete')->with('node', '42');
        $storage->expects($this->never())->method('store');
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willThrowException(new \RuntimeException('read failed'));
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $logger = new EmbeddingListenerRecordingLogger();

        $listener = new EntityEmbeddingListener(storage: $storage, embeddingProvider: $this->createStub(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class), logger: $logger, entityTypeManager: $manager, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(id: 42, entityTypeId: 'node')));

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('read failed', $logger->errors[0]);
    }

    #[Test]
    public function a_missing_re_sourced_entity_removes_the_vector_without_embedding_or_throwing(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::once())->method('delete')->with('node', '42');
        $storage->expects(self::never())->method('store');
        $provider = $this->createMock(\Waaseyaa\AI\Vector\EmbeddingSaveProviderInterface::class);
        $provider->expects(self::never())->method('embedForSave');

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager(null),
            indexPolicy: $this->nodePolicy(),
            executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(),
        );

        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(id: 42, entityTypeId: 'node')));
    }

    private function entityTypeManager(?EntityInterface $servedEntity): EntityTypeManagerInterface
    {
        return new class ($servedEntity) implements EntityTypeManagerInterface {
            public function __construct(private readonly ?EntityInterface $servedEntity) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(id: $entityTypeId, label: 'x', class: \stdClass::class, keys: ['id' => 'id']);
            }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
            {
                return [];
            }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array
            {
                return [];
            }
            public function hasDefinition(string $entityTypeId): bool
            {
                return true;
            }
            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \LogicException('not needed');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $servedEntity = $this->servedEntity;

                return new class ($servedEntity) implements EntityRepositoryInterface {
                    public function __construct(private readonly ?EntityInterface $servedEntity) {}
                    public function create(array $values = []): EntityInterface
                    {
                        throw new \LogicException('not needed');
                    }
                    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
                    {
                        return $this->servedEntity;
                    }
                    public function loadWorkingCopy(int|string $id): ?EntityInterface
                    {
                        return $this->find($id);
                    }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
                    {
                        return [];
                    }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
                    {
                        return [];
                    }
                    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
                    {
                        throw new \LogicException('not needed');
                    }
                    public function save(EntityInterface $entity, bool $validate = true): int
                    {
                        throw new \LogicException('not needed');
                    }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(int|string $id): bool
                    {
                        return true;
                    }
                    public function count(array $criteria = []): int
                    {
                        return 0;
                    }
                    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface
                    {
                        return null;
                    }
                    public function rollback(int|string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
                    {
                        throw new \LogicException('not needed');
                    }
                    public function listRevisions(int|string $entityId): array
                    {
                        return [];
                    }
                    public function setCurrentRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
                    {
                        throw new \LogicException('not needed');
                    }
                    public function loadPublishedRevision(int|string $entityId): ?EntityInterface
                    {
                        return null;
                    }
                    public function setPublishedRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
                    {
                        throw new \LogicException('not needed');
                    }
                    public function saveMany(array $entities, bool $validate = true): array
                    {
                        return [];
                    }
                    public function deleteMany(array $entities): int
                    {
                        return 0;
                    }
                    public function findTranslations(EntityInterface $entity): array
                    {
                        return [];
                    }
                    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int
                    {
                        return 0;
                    }
                    public function loadTranslation(int|string $entityId, string $langcode): ?EntityInterface
                    {
                        return null;
                    }
                    public function listTranslationRevisions(int|string $entityId, string $langcode): array
                    {
                        return [];
                    }
                };
            }
        };
    }

    private function nodePolicy(): EmbeddingIndexPolicy
    {
        return EmbeddingIndexPolicy::fromArray([
            'ai' => [
                'vector_index' => [
                    'node' => [
                        'fields' => ['label', 'title', 'body', 'description', 'name'],
                        'allow_external' => true,
                    ],
                ],
            ],
        ]);
    }
}

final readonly class TestEmbeddingEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values = [],
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }
    public function uuid(): string
    {
        return 'uuid';
    }
    public function label(): string
    {
        return 'Label';
    }
    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
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
        throw new \LogicException('Readonly');
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

final class EmbeddingListenerRecordingLogger implements \Waaseyaa\Foundation\Log\LoggerInterface
{
    use \Waaseyaa\Foundation\Log\LoggerTrait;

    /** @var list<string> */
    public array $errors = [];

    public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === \Waaseyaa\Foundation\Log\LogLevel::ERROR) {
            $this->errors[] = (string) $message;
        }
    }
}
