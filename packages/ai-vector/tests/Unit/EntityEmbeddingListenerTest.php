<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
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
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;

#[CoversClass(EntityEmbeddingListener::class)]
final class EntityEmbeddingListenerTest extends TestCase
{
    #[Test]
    public function dispatchesEmbeddingMessageOnPostSave(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (object $message): bool {
                if (!$message instanceof GenericMessage) {
                    return false;
                }

                return $message->type === 'ai_vector.embed_entity'
                    && ($message->payload['entity_type'] ?? null) === 'node'
                    && ($message->payload['entity_id'] ?? null) === '42'
                    && ($message->payload['langcode'] ?? null) === 'en';
            }));

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 1, 'workflow_state' => 'published', 'title' => 'Published node'],
        )));
    }

    #[Test]
    public function skipsDispatchWhenEntityIdIsMissing(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('dispatch');

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: null,
            entityTypeId: 'node',
            values: ['status' => 1, 'workflow_state' => 'published'],
        )));
    }

    #[Test]
    public function doesNotDispatchForUnpublishedNodeState(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('dispatch');

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 0, 'workflow_state' => 'draft'],
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
            queue: null,
            storage: $storage,
            embeddingProvider: null,
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
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())
            ->method('embed')
            ->with($this->stringContains('Vector Title'))
            ->willReturn([0.1, 0.2, 0.3]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->with('node', '42', [0.1, 0.2, 0.3]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
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

        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with($this->stringContains('Still live'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager($servedEntity),
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

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with($this->logicalAnd(
            $this->stringContains('Published title'),
            $this->logicalNot($this->stringContains('Draft title')),
        ))->willReturn([0.1]);
        $storage->method('store');

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager($publishedEntity),
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
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with($this->stringContains('Promoted content'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager($promotedEntity),
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
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager($servedEntity),
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
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())->method('embed')->with($this->stringContains('Rolled back'))->willReturn([0.1]);
        $storage->expects($this->once())->method('store')->with('node', '42', [0.1]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
            entityTypeManager: $this->entityTypeManager($servedEntity),
        );
        $listener->onRevisionReverted(new EntityEvent($eventEntity));
    }

    #[Test]
    public function a_pointer_move_with_no_entity_type_manager_wired_no_ops(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('store');
        $storage->expects($this->never())->method('delete');

        $listener = new EntityEmbeddingListener(queue: null, storage: $storage, embeddingProvider: $this->createMock(EmbeddingProviderInterface::class));
        $listener->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '42',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
        ));
    }

    private function entityTypeManager(?EntityInterface $servedEntity): EntityTypeManagerInterface
    {
        return new class ($servedEntity) implements EntityTypeManagerInterface {
            public function __construct(private readonly ?EntityInterface $servedEntity) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { return new EntityType(id: $entityTypeId, label: 'x', class: \stdClass::class, keys: ['id' => 'id']); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $servedEntity = $this->servedEntity;

                return new class ($servedEntity) implements EntityRepositoryInterface {
                    public function __construct(private readonly ?EntityInterface $servedEntity) {}
                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->servedEntity; }
                    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return true; }
                    public function count(array $criteria = []): int { return 0; }
                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
                    public function rollback(string $entityId, int $targetRevisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(string $entityId): array { return []; }
                    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(string $entityId): ?EntityInterface { return null; }
                    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(EntityInterface $entity): array { return []; }
                    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int { return 0; }
                    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
                    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }
}

final readonly class TestEmbeddingEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values = [],
    ) {}

    public function id(): int|string|null { return $this->id; }
    public function uuid(): string { return 'uuid'; }
    public function label(): string { return 'Label'; }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}
