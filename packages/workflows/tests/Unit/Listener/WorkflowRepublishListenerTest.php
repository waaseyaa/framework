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

    private function entityTypeManagerRecordingPromotions(WorkflowRepublishListenerPromotionRecorder $recorderObject): EntityTypeManagerInterface
    {
        $recorder = static function (string $id, int $revisionId) use ($recorderObject): void {
            $recorderObject->calls[] = [$id, $revisionId];
        };

        return new class ($recorder) implements EntityTypeManagerInterface {
            public function __construct(private readonly \Closure $recorder) {}
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

                return new class ($recorder) implements EntityRepositoryInterface {
                    public function __construct(private readonly \Closure $recorder) {}
                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return null; }
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
