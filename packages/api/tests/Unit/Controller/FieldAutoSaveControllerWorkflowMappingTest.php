<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): a real
 * `TransitionDeniedException` thrown from `save()` (WorkflowStateGuard's
 * PRE_SAVE denial, or the same-state-republish gate — see
 * `WorkflowStateGuardTest`/`GuardWiringTest` for the guard's own coverage)
 * must map to the documented 403/422 policy here, never surface as an
 * uncaught 500. This is a pure mapping test — the repository stub throws
 * directly; the wiring proof that WorkflowStateGuard actually fires on a
 * real save lives in `packages/workflows/tests/Integration/GuardWiringTest.php`.
 *
 * @covers \Waaseyaa\Api\Controller\FieldAutoSaveController
 */
#[CoversClass(FieldAutoSaveController::class)]
final class FieldAutoSaveControllerWorkflowMappingTest extends TestCase
{
    #[Test]
    public function a_permission_denial_maps_to_403(): void
    {
        $response = $this->runUpdateWithDeniedSave(
            new TransitionDeniedException(TransitionDeniedException::REASON_PERMISSION, 'Account lacks the required permission.'),
        );

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('workflow_transition_denied', $body['errors'][0]['code']);
    }

    #[Test]
    public function an_illegal_edge_denial_maps_to_422(): void
    {
        $response = $this->runUpdateWithDeniedSave(
            new TransitionDeniedException(TransitionDeniedException::REASON_ILLEGAL_EDGE, 'No transition for that edge.'),
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('workflow_transition_denied', $body['errors'][0]['code']);
    }

    private function runUpdateWithDeniedSave(TransitionDeniedException $denial): \Symfony\Component\HttpFoundation\Response
    {
        $entity = $this->entity();
        $repository = new class ($entity, $denial) implements EntityRepositoryInterface {
            public function __construct(private readonly EntityInterface $entity, private readonly TransitionDeniedException $denial) {}
            public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
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

        $fieldRegistry = new FieldDefinitionRegistry();
        $fieldRegistry->registerBundleFields('article', 'article', [
            new FieldDefinition(name: 'title', type: 'string', targetEntityTypeId: 'article', targetBundle: 'article', label: 'Title'),
        ]);

        $account = new AuthorizationPrincipal(1, true, ['authenticated'], ['administer content'], 'test');

        $controller = new FieldAutoSaveController($entityTypeManager, $accessHandler, $fieldRegistry);

        $request = Request::create(
            '/api/article/1/field/title',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['value' => 'New title'], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', $account);

        return $controller->update($request, 'article', '1', 'title');
    }

    private function entity(): EntityInterface
    {
        return new class implements EntityInterface {
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
        };
    }
}
