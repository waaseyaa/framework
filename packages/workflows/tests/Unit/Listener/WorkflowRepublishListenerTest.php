<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Listener\WorkflowRepublishListener;
use Waaseyaa\Workflows\Republish\RepublishMarker;

/**
 * @covers \Waaseyaa\Workflows\Listener\WorkflowRepublishListener
 */
#[CoversClass(WorkflowRepublishListener::class)]
final class WorkflowRepublishListenerTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function entity(array $values, bool $isNew = false): EntityInterface&RevisionableEntityInterface
    {
        return new class ($values, $isNew) implements EntityInterface, RevisionableEntityInterface {
            use RevisionableEntityTrait;

            public function __construct(private array $values, private readonly bool $new) {}

            public function id(): int|string|null { return $this->values['id'] ?? null; }
            public function uuid(): string { return 'u-1'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return $this->new; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }

            public function set(string $name, mixed $value): static
            {
                $this->values[$name] = $value;

                return $this;
            }

            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
        };
    }

    /**
     * @param EntityInterface|null $baseRow What the fixture repository's find()
     *   returns — the SERVED base row the listener consults for its
     *   already-published self-skip (fix-wave: promotion of the already-
     *   published revision is a no-op and must not emit pointer events).
     *   Null models "row vanished" (the listener then has no live pointer
     *   to compare against and proceeds with the promotion attempt).
     */
    private function entityTypeManagerRecordingPromotions(WorkflowRepublishListenerPromotionRecorder $recorderObject, ?EntityInterface $baseRow = null): EntityTypeManagerInterface
    {
        $recorder = static function (string $id, int $revisionId) use ($recorderObject): void {
            $recorderObject->calls[] = [$id, $revisionId];
        };

        return new class ($recorder, $baseRow) implements EntityTypeManagerInterface {
            public function __construct(private readonly \Closure $recorder, private readonly ?EntityInterface $baseRow) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { throw new \LogicException('not needed'); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $recorder = $this->recorder;
                $baseRow = $this->baseRow;

                return new class ($recorder, $baseRow) implements EntityRepositoryInterface {
                    public function __construct(private readonly \Closure $recorder, private readonly ?EntityInterface $baseRow) {}
                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->baseRow; }
                    public function loadWorkingCopy(string $id): ?EntityInterface { return null; }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return true; }
                    public function count(array $criteria = []): int { return 0; }
                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
                    public function rollback(string $entityId, int $targetRevisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(string $entityId): array { return []; }
                    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(string $entityId): ?EntityInterface { return null; }

                    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
                    {
                        ($this->recorder)($entityId, $revisionId);

                        return new class implements EntityInterface {
                            public function id(): int|string|null { return null; }
                            public function uuid(): string { return 'u-1'; }
                            public function label(): string { return 'Fixture'; }
                            public function getEntityTypeId(): string { return 'fixture'; }
                            public function bundle(): string { return 'article'; }
                            public function isNew(): bool { return false; }
                            public function get(string $name): mixed { return null; }
                            public function set(string $name, mixed $value): static { return $this; }
                            public function toArray(): array { return []; }
                            public function language(): string { return 'en'; }
                        };
                    }

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

    #[Test]
    public function an_armed_entity_is_promoted_through_setPublishedRevision(): void
    {
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1, 'revision_id' => 42]);
        $marker->arm($entity);

        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame([['1', 42]], $recorder->calls);
    }

    #[Test]
    public function an_unarmed_entity_is_never_promoted(): void
    {
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1, 'revision_id' => 42]);

        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame([], $recorder->calls);
    }

    #[Test]
    public function consuming_the_marker_prevents_a_second_promotion(): void
    {
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1, 'revision_id' => 42]);
        $marker->arm($entity);

        $listener->onPostSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertCount(1, $recorder->calls, 'The marker must be consumed exactly once per arm.');
    }

    #[Test]
    public function an_armed_entity_with_no_revision_id_is_not_promoted(): void
    {
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1]); // no setRevisionId() call
        $marker->arm($entity);

        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame([], $recorder->calls);
    }

    #[Test]
    public function an_armed_entity_whose_revision_already_is_the_live_published_pointer_skips_the_promotion(): void
    {
        // Fix-wave (#1920 PR-2 adversarial review): a spurious arm — the
        // guard armed at PRE_SAVE on the assumption the save would create a
        // revision, but a later PRE_SAVE listener (NodeRevisionDefaultListener
        // in the guard-first order) turned it into an in-place save of the
        // published tip — must not promote the ALREADY-published revision:
        // that would re-fire pointer events/audit/reindex for a no-op move.
        // The listener self-skips when the entity's revision id equals the
        // base row's live published_revision_id.
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $baseRow = $this->entity(['id' => 1, 'revision_id' => 42, 'published_revision_id' => 42]);
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder, $baseRow);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1, 'revision_id' => 42]);
        $marker->arm($entity);

        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame([], $recorder->calls, 'Promotion of the already-published revision must self-skip.');
        $this->assertFalse($marker->consume($entity), 'The marker must still have been consumed.');
    }

    #[Test]
    public function an_armed_entity_whose_revision_diverges_from_the_live_pointer_still_promotes(): void
    {
        $recorder = new WorkflowRepublishListenerPromotionRecorder();
        $baseRow = $this->entity(['id' => 1, 'revision_id' => 41, 'published_revision_id' => 41]);
        $entityTypeManager = $this->entityTypeManagerRecordingPromotions($recorder, $baseRow);
        $marker = new RepublishMarker();
        $listener = new WorkflowRepublishListener($marker, $entityTypeManager);

        $entity = $this->entity(['id' => 1, 'revision_id' => 42]); // new tip, ahead of the pointer
        $marker->arm($entity);

        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame([['1', 42]], $recorder->calls);
    }
}

/**
 * Mutable call recorder for `setPublishedRevision()` invocations — a plain
 * object (not a by-reference closure capture) so the shared
 * `entityTypeManagerRecordingPromotions()` fixture factory can hand each
 * test its own independent recorder without PHP's by-value array-destructure
 * semantics silently dropping the mutations.
 */
final class WorkflowRepublishListenerPromotionRecorder
{
    /** @var list<array{string, int}> */
    public array $calls = [];
}
