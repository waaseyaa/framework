<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

/**
 * @covers \Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber
 */
#[CoversClass(SearchIndexSubscriber::class)]
final class SearchIndexSubscriberTest extends TestCase
{
    #[Test]
    public function it_subscribes_to_post_save_and_post_delete(): void
    {
        $events = SearchIndexSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::POST_SAVE->value, $events);
        $this->assertArrayHasKey(EntityEvents::POST_DELETE->value, $events);
    }

    #[Test]
    public function it_indexes_on_post_save_when_entity_is_indexable(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('index')->with($entity);

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostSave($event);
    }

    #[Test]
    public function it_skips_non_indexable_entities_on_save(): void
    {
        $entity = new class (['id' => 1]) extends \Waaseyaa\Entity\EntityBase {
            public function __construct(array $values) {
                parent::__construct($values, 'dummy', ['id' => 'id']);
            }
        };
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->never())->method('index');

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostSave($event);
    }

    #[Test]
    public function it_removes_on_post_delete_when_entity_is_indexable(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('remove')->with('node:1');

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostDelete($event);
    }

    #[Test]
    public function it_catches_indexing_errors_without_crashing(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->method('index')->willThrowException(new \RuntimeException('DB error'));

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);

        // Should not throw — best-effort side effect
        $subscriber->onPostSave($event);
        $this->assertTrue(true); // No exception = pass
    }

    // ------------------------------------------------------------------
    // CW-v1 option-1 (#1920 PR-2, design §3.3): re-source from find(),
    // plus the RevisionPointerMovedEvent / REVISION_REVERTED subscriptions.
    // ------------------------------------------------------------------

    #[Test]
    public function it_subscribes_to_the_pointer_move_and_revision_reverted_events_too(): void
    {
        $events = SearchIndexSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(RevisionPointerMovedEvent::class, $events);
        $this->assertArrayHasKey(EntityEvents::REVISION_REVERTED->value, $events);
    }

    #[Test]
    public function on_post_save_indexes_the_re_sourced_served_entity_not_the_in_memory_tip(): void
    {
        // The de-index/draft-leak pin: an in-memory 'draft' tip must never
        // reach the indexer directly — only what find() (the served base
        // row) actually reports.
        $servedEntity = $this->createIndexableEntity('node:1', title: 'Served (published) content');
        $tipEntity = $this->createIndexableEntity('node:1', title: 'Unreviewed draft tip');

        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('index')->with($servedEntity);

        $subscriber = new SearchIndexSubscriber($indexer, entityTypeManager: $this->entityTypeManager($servedEntity));
        $subscriber->onPostSave(new EntityEvent($tipEntity));
    }

    #[Test]
    public function a_standalone_pointer_move_reindexes_via_find(): void
    {
        $servedEntity = $this->createIndexableEntity('node:1', title: 'Newly promoted content');
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('index')->with($servedEntity);

        $subscriber = new SearchIndexSubscriber($indexer, entityTypeManager: $this->entityTypeManager($servedEntity));
        $subscriber->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
        ));
    }

    #[Test]
    public function a_revision_reverted_event_reindexes_via_find(): void
    {
        $servedEntity = $this->createIndexableEntity('node:1', title: 'Rolled-back content');
        $eventEntity = $this->createIndexableEntity('node:1', title: 'The revision object the event happened to carry');

        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('index')->with($servedEntity);

        $subscriber = new SearchIndexSubscriber($indexer, entityTypeManager: $this->entityTypeManager($servedEntity));
        $subscriber->onRevisionReverted(new EntityEvent($eventEntity));
    }

    #[Test]
    public function a_pointer_move_with_no_entity_type_manager_wired_no_ops(): void
    {
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->never())->method('index');

        $subscriber = new SearchIndexSubscriber($indexer);
        $subscriber->onRevisionPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
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

    private function createMockIndexer(): SearchIndexerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(SearchIndexerInterface::class);
    }

    private function createIndexableEntity(string $docId, string $title = 'Test'): SearchIndexableInterface&\Waaseyaa\Entity\EntityInterface
    {
        return new class ($docId, $title) extends \Waaseyaa\Entity\EntityBase implements SearchIndexableInterface {
            private string $docId;
            private string $title;

            public function __construct(string $docId, string $title) {
                parent::__construct(['id' => 1], 'node', ['id' => 'id']);
                $this->docId = $docId;
                $this->title = $title;
            }

            public function getSearchDocumentId(): string { return $this->docId; }
            public function toSearchDocument(): array { return ['title' => $this->title, 'body' => 'Content']; }
            public function toSearchMetadata(): array { return ['entity_type' => 'node']; }
        };
    }
}
