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
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.2 finding A4 / §3.1 finding A2):
 * `WorkflowTransitionController::transition()` must map a REAL
 * `RevisionConflictException` — thrown by `TransitionService::transition()`'s
 * deterministic content rule when the passed entity's revision id disagrees
 * with the working copy's — to a 409, not an uncaught 500.
 *
 * **Updated for #1920 PR-3 (design §4 item 1):** the controller now resolves
 * `loadWorkingCopy()` ITSELF and passes that (not the `find()`-loaded gate
 * entity) as the transition target — "passing it explicitly avoids the
 * conflict path for this first-party caller" (PR-3 brief). A caller-supplied
 * STALE object can therefore no longer trigger this conflict through the
 * controller — the only way to reach `RevisionConflictException` here now is
 * a genuine RACE: a concurrent transition landing between the controller's
 * own `loadWorkingCopy()` call and `TransitionService::transition()`'s
 * internal one. The fixture repository below models exactly that: its
 * `loadWorkingCopy()` returns a DIFFERENT (newer) revision on its SECOND
 * invocation, simulating the race window rather than caller staleness.
 *
 * @covers \Waaseyaa\Api\Controller\WorkflowTransitionController
 */
#[CoversClass(WorkflowTransitionController::class)]
final class WorkflowTransitionControllerRevisionConflictTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'wf_conflict_article';

    #[Test]
    public function a_concurrent_transition_landing_mid_request_maps_to_409(): void
    {
        // find() only feeds the R8 view gate now (PR-3) — its content no
        // longer rides the transition.
        $gateEntity = $this->entity(revisionId: 3, state: 'draft');
        // First loadWorkingCopy() call (the controller's own PR-3 resolve).
        $controllerFetchedCopy = $this->entity(revisionId: 9, state: 'draft');
        // Second call (TransitionService's internal resolve) — a race
        // winner landed in between.
        $raceWinnerCopy = $this->entity(revisionId: 15, state: 'draft');

        $repository = new ConflictFixtureRepository($gateEntity, $controllerFetchedCopy, $raceWinnerCopy);
        $entityType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'WF Conflict Article',
            class: ConflictFixtureEntity::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type', 'revision' => 'vid'],
            revisionable: true,
        );
        $workflow = new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
            ],
        ]);
        $workflowRepository = new ConflictFixtureWorkflowLookupRepository($workflow);
        $entityTypeManager = new ConflictFixtureEntityTypeManager($entityType, $repository, $workflowRepository);
        $configFactory = new ConflictFixtureConfigFactory([self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial']);
        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $transitionService = new TransitionService($bindings, $entityTypeManager);

        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $account = $this->account(['use editorial transition submit_for_review']);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        $request = Request::create('/api/' . self::ENTITY_TYPE_ID . '/1/workflow/transition', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['transition' => 'submit_for_review'], JSON_THROW_ON_ERROR));
        $request->attributes->set('_account', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '1');

        $this->assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('REVISION_CONFLICT', $body['errors'][0]['code']);
        $this->assertSame(9, $body['errors'][0]['meta']['expected_revision_id'], 'expected_revision_id is what the CONTROLLER passed (its own loadWorkingCopy() resolve).');
        $this->assertSame(15, $body['errors'][0]['meta']['current_revision_id'], "current_revision_id is the SERVICE's own (later) loadWorkingCopy() resolve — the race winner.");
    }

    private function entity(int $revisionId, string $state): ConflictFixtureEntity
    {
        return new ConflictFixtureEntity('1', $revisionId, $state);
    }

    private function allowAllPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
        };
    }

    private function account(array $permissions): AccountInterface
    {
        return new class ($permissions) implements AccountInterface {
            public function __construct(private readonly array $permissions) {}
            public function id(): int|string { return 1; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return ['authenticated']; }
            public function isAuthenticated(): bool { return true; }
        };
    }
}

final class ConflictFixtureEntity implements EntityInterface, RevisionableInterface
{
    private ?bool $newRevisionOverride = null;
    private ?string $revisionLog = null;

    /** @var array<string, mixed> */
    private array $values;

    public function __construct(string $id, private int $revisionId, string $state)
    {
        $this->values = ['id' => $id, 'workflow_state' => $state, 'status' => 0, 'type' => WorkflowTransitionControllerRevisionConflictTest::class];
    }

    public function id(): int|string|null { return $this->values['id']; }
    public function uuid(): string { return 'u-' . (string) $this->values['id']; }
    public function label(): string { return 'Fixture'; }
    public function getEntityTypeId(): string { return 'wf_conflict_article'; }
    public function bundle(): string { return 'wf_conflict_article'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }

    public function getRevisionId(): ?int { return $this->revisionId; }
    public function isDefaultRevision(): bool { return true; }
    public function isLatestRevision(): bool { return true; }
    public function setNewRevision(bool $value): void { $this->newRevisionOverride = $value; }
    public function isNewRevision(): ?bool { return $this->newRevisionOverride; }
    public function setRevisionLog(?string $log): void { $this->revisionLog = $log; }
    public function getRevisionLog(): ?string { return $this->revisionLog; }
}

final class ConflictFixtureRepository implements EntityRepositoryInterface
{
    private int $loadWorkingCopyCalls = 0;

    /**
     * $raceWinnerCopy defaults to $workingCopy (every call returns the same
     * object — the no-race shape most fixture instantiations want).
     * {@see WorkflowTransitionControllerRevisionConflictTest} passes a
     * genuinely different third entity to model a concurrent transition
     * landing between the controller's own `loadWorkingCopy()` call (PR-3)
     * and `TransitionService::transition()`'s internal one.
     */
    public function __construct(
        private readonly ConflictFixtureEntity $staleEntity,
        private readonly ConflictFixtureEntity $workingCopy,
        private readonly ?ConflictFixtureEntity $raceWinnerCopy = null,
    ) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->staleEntity; }

    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        ++$this->loadWorkingCopyCalls;

        return $this->loadWorkingCopyCalls >= 2 && $this->raceWinnerCopy !== null
            ? $this->raceWinnerCopy
            : $this->workingCopy;
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('save() must not be reached: the conflict must be detected first'); }
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

final class ConflictFixtureWorkflowLookupRepository implements EntityRepositoryInterface
{
    public function __construct(private readonly ?Workflow $workflow) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
    public function delete(EntityInterface $entity): void {}
    public function exists(string $id): bool { return $this->workflow !== null; }
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

final class ConflictFixtureEntityTypeManager implements EntityTypeManagerInterface
{
    public function __construct(
        private readonly EntityType $entityType,
        private readonly EntityRepositoryInterface $repository,
        private readonly ConflictFixtureWorkflowLookupRepository $workflowRepository,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface { return $this->entityType; }
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
    public function getDefinitions(): array { return [$this->entityType->id() => $this->entityType]; }
    public function hasDefinition(string $entityTypeId): bool { return $entityTypeId === $this->entityType->id(); }
    public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $entityTypeId === 'workflow' ? $this->workflowRepository : $this->repository;
    }
}

final class ConflictFixtureConfigFactory implements ConfigFactoryInterface
{
    /**
     * @param array<string, string> $assignments
     */
    public function __construct(private readonly array $assignments) {}

    public function get(string $name): ConfigInterface
    {
        $data = $this->assignments;

        return new class ($data) implements ConfigInterface {
            public function __construct(private readonly array $data) {}
            public function getName(): string { return 'workflows.assignments'; }
            public function get(string $key = ''): mixed { return $key === '' ? $this->data : ($this->data[$key] ?? null); }
            public function set(string $key, mixed $value): static { return $this; }
            public function clear(string $key): static { return $this; }
            public function delete(): static { return $this; }
            public function save(): static { return $this; }
            public function isNew(): bool { return $this->data === []; }
            public function getRawData(): array { return $this->data; }
        };
    }

    public function getEditable(string $name): ConfigInterface { return $this->get($name); }
    public function loadMultiple(array $names): array { return []; }
    public function rename(string $oldName, string $newName): static { return $this; }
    public function listAll(string $prefix = ''): array { return []; }
}
