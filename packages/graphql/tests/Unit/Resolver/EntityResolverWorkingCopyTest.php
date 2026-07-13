<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Resolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4 item 6): `resolveUpdate()`'s SAVE
 * TARGET — and the echo-comparison basis fed into
 * `EntityWritePayloadGuard::evaluateForUpdate()` — must be the WORKING COPY,
 * not the `find()`-loaded entity. Modeled with a repository whose `find()`
 * and `loadWorkingCopy()` return DIFFERENT objects, mirroring
 * `EntityResolverWorkflowMappingTest`'s fixture shape.
 *
 * @covers \Waaseyaa\GraphQL\Resolver\EntityResolver
 */
#[CoversClass(EntityResolver::class)]
final class EntityResolverWorkingCopyTest extends TestCase
{
    #[Test]
    public function resolve_update_lands_on_the_working_copy_and_echoes_its_own_revision_id(): void
    {
        // The found entity (find()) carries the PUBLISHED pointer's
        // revision_id (5). The working copy (the tip) carries a DIFFERENT,
        // higher one (9) — a forward draft in flight. The client read the
        // working copy (e.g. via a working-copy-aware read) and echoes back
        // ITS OWN revision_id (9) alongside a genuine content edit: this
        // must be accepted as a pure echo (evaluateForUpdate() must compare
        // against the TARGET's stored values, not the found entity's), and
        // the edit must land on the working copy object, not the found one.
        $foundEntity = $this->entity(id: 1, title: 'Published title', revisionId: 5);
        $workingCopy = $this->entity(id: 1, title: 'Draft title', revisionId: 9);

        $repository = new UpdateDivergentRepository($foundEntity, $workingCopy);
        $entityTypeManager = new class ($repository) implements EntityTypeManagerInterface {
            public function __construct(private readonly EntityRepositoryInterface $repository) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { return new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id', 'label' => 'title', 'revision' => 'revision_id']); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface { return $this->repository; }
        };

        $allowAllPolicy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
        };
        $accessHandler = new EntityAccessHandler([$allowAllPolicy]);

        $account = new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['authenticated']; }
        };

        $guard = new GraphQlAccessGuard($accessHandler, $account);
        $resolver = new EntityResolver($entityTypeManager, $guard, $account);

        $result = $resolver->resolveUpdate('article', 1, [
            'title' => 'Edited via GraphQL',
            'revision_id' => 9, // echoes the WORKING COPY's own revision_id
        ]);

        self::assertSame($workingCopy, $repository->savedEntity, 'save() must be called with the WORKING COPY object, not the found entity.');
        self::assertSame('Edited via GraphQL', $workingCopy->get('title'));
        self::assertSame('Published title', $foundEntity->get('title'), 'The found (published) entity must be untouched.');
        self::assertSame('Edited via GraphQL', $result['title']);
    }

    private function entity(int $id, string $title, int $revisionId): EntityInterface&FieldableInterface
    {
        return new class ($id, $title, $revisionId) implements EntityInterface, FieldableInterface {
            private array $values;
            public function __construct(int $id, string $title, int $revisionId) {
                $this->values = ['id' => $id, 'title' => $title, 'revision_id' => $revisionId];
            }
            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'u-' . (string) $this->values['id']; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'article'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { $this->values[$name] = $value; return $this; }
            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
            public function hasField(string $name): bool { return \array_key_exists($name, $this->values); }
            public function getFieldDefinitions(): array { return []; }
        };
    }
}

final class UpdateDivergentRepository implements EntityRepositoryInterface
{
    public ?EntityInterface $savedEntity = null;

    public function __construct(
        private readonly EntityInterface $foundEntity,
        private readonly EntityInterface $workingCopy,
    ) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->foundEntity; }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->workingCopy; }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->savedEntity = $entity;

        return 1;
    }

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
}
