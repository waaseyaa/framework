<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Resolver;

use GraphQL\Error\UserError;
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
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): a real
 * `TransitionDeniedException` thrown from `save()` must map to a
 * `GraphQL\Error\UserError` (client-safe, real message) rather than being
 * masked by webonyx/graphql-php as a generic "Internal server error"
 * (the default treatment for any non-ClientAware exception thrown inside a
 * resolver). Pure mapping test — the repository stub throws directly; the
 * guard's own denial logic is covered by `WorkflowStateGuardTest`/
 * `GuardWiringTest` in `waaseyaa/workflows`.
 *
 * @covers \Waaseyaa\GraphQL\Resolver\EntityResolver
 */
#[CoversClass(EntityResolver::class)]
final class EntityResolverWorkflowMappingTest extends TestCase
{
    #[Test]
    public function resolve_create_maps_a_transition_denial_to_a_user_error(): void
    {
        $denial = new TransitionDeniedException(TransitionDeniedException::REASON_PERMISSION, 'Account lacks the required permission.');
        $resolver = $this->resolver($denial);

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Account lacks the required permission.');

        $resolver->resolveCreate('article', ['title' => 'New']);
    }

    #[Test]
    public function resolve_update_maps_a_transition_denial_to_a_user_error(): void
    {
        $denial = new TransitionDeniedException(TransitionDeniedException::REASON_ILLEGAL_EDGE, 'No transition for that edge.');
        $resolver = $this->resolver($denial);

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('No transition for that edge.');

        $resolver->resolveUpdate('article', 1, ['title' => 'Updated']);
    }

    private function resolver(TransitionDeniedException $denial): EntityResolver
    {
        $entity = $this->entity();
        $repository = new class ($entity, $denial) implements EntityRepositoryInterface {
            public function __construct(private readonly EntityInterface $entity, private readonly TransitionDeniedException $denial) {}
            public function create(array $values = []): EntityInterface { return $this->entity; }
            public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->entity; }
            public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
            public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
            public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
            public function save(EntityInterface $entity, bool $validate = true): int { throw $this->denial; }
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

        $entityTypeManager = new class ($repository) implements EntityTypeManagerInterface {
            public function __construct(private readonly EntityRepositoryInterface $repository) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { return new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id']); }
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

        return new EntityResolver($entityTypeManager, $guard, $account);
    }

    private function entity(): EntityInterface&FieldableInterface
    {
        return new class implements EntityInterface, FieldableInterface {
            private array $values = ['id' => 1, 'title' => 'Original'];
            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'u-1'; }
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
