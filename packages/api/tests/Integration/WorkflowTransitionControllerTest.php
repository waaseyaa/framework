<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

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
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * Controller-level integration tests for {@see WorkflowTransitionController}
 * (CW-v1 WP-4, docs/specs/content-workflow.md "Integration -> API (WP-4)").
 *
 * Follows {@see \Waaseyaa\Api\Tests\Integration\FieldAutoSaveTest}'s shape
 * (in-memory fixtures, `_account` set directly on the Request, anonymous
 * access-policy classes) but needs its own lightweight
 * `EntityRepositoryInterface`/`EntityTypeManagerInterface` fixtures rather
 * than the shared `InMemoryEntityRepository`: `TransitionService::transition()`
 * unconditionally calls `loadPublishedRevision()` before it even checks
 * whether the entity type is revisionable, and the shared in-memory
 * repository fixture throws for that method (it does not support revisions
 * at all) — see {@see FixtureWorkflowEntityRepository} below.
 */
#[CoversClass(WorkflowTransitionController::class)]
final class WorkflowTransitionControllerTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'wf_article';

    // --- 401 unauthenticated ---

    #[Test]
    public function missingAccountReturns401OnTransitionsEndpoint(): void
    {
        [$controller] = $this->boundWorld(denyView: false);
        $request = Request::create('/api/wf_article/1/workflow/transitions', 'GET');

        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '1');

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('unauthenticated', $body['errors'][0]['code']);
    }

    #[Test]
    public function missingAccountReturns401OnTransitionEndpoint(): void
    {
        [$controller] = $this->boundWorld(denyView: false);
        $request = Request::create('/api/wf_article/1/workflow/transition', 'POST', [], [], [], [], '{"transition":"publish"}');

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '1');

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('unauthenticated', $body['errors'][0]['code']);
    }

    // --- 404 missing entity ---

    #[Test]
    public function missingEntityReturns404(): void
    {
        [$controller, , , $account] = $this->boundWorld(denyView: false);
        $request = $this->requestWithAccount('GET', '/api/wf_article/999/workflow/transitions', $account);

        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '999');

        $this->assertSame(404, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('404', $body['errors'][0]['status']);
    }

    // --- 404 view-denied, byte-identical to missing (R8 oracle standard) ---

    #[Test]
    public function viewDeniedIsByteIdenticalToMissingEntity(): void
    {
        [$deniedController, $deniedRepository, , $account] = $this->boundWorld(denyView: true);
        $deniedRepository->addEntity(new FixtureWorkflowEntity('42', 'draft'));

        [$missingController, , , $missingAccount] = $this->boundWorld(denyView: true);
        // No entity added in this world — id '42' never existed.

        $deniedRequest = $this->requestWithAccount('GET', '/api/wf_article/42/workflow/transitions', $account);
        $missingRequest = $this->requestWithAccount('GET', '/api/wf_article/42/workflow/transitions', $missingAccount);

        $denied = $deniedController->transitions($deniedRequest, self::ENTITY_TYPE_ID, '42');
        $missing = $missingController->transitions($missingRequest, self::ENTITY_TYPE_ID, '42');

        $this->assertSame(404, $denied->getStatusCode());
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(
            json_encode($this->decode($missing), JSON_THROW_ON_ERROR),
            json_encode($this->decode($denied), JSON_THROW_ON_ERROR),
            'A view-denied entity must be byte-identical to a missing one (R8 oracle standard).',
        );
    }

    // --- GET available transitions ---

    #[Test]
    public function getTransitionsReturnsOnlyWhatTheServiceMakesAvailable(): void
    {
        [$controller, $repository, , ] = $this->boundWorld(denyView: false, permissions: ['use editorial transition submit_for_review']);
        $repository->addEntity(new FixtureWorkflowEntity('7', 'draft'));
        $account = $this->account(9, ['use editorial transition submit_for_review']);
        $request = $this->requestWithAccount('GET', '/api/wf_article/7/workflow/transitions', $account);

        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '7');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertCount(1, $body['data']);
        $this->assertSame('submit_for_review', $body['data'][0]['id']);
        $this->assertSame('review', $body['data'][0]['to']);
        $this->assertSame('draft', $body['meta']['workflow_state']);
    }

    #[Test]
    public function getTransitionsForUnboundEntityTypeReturns200EmptyData(): void
    {
        [$controller, $repository] = $this->unboundWorld();
        $repository->addEntity(new FixtureWorkflowEntity('3', 'draft'));
        $account = $this->account(9, ['use editorial transition publish']);
        $request = $this->requestWithAccount('GET', '/api/wf_article/3/workflow/transitions', $account);

        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '3');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame([], $body['data']);
    }

    // --- field-level view gate on meta.workflow_state (PR #1956 reviewer finding) ---

    #[Test]
    public function metaWorkflowStateIsNullWhenFieldViewIsForbidden(): void
    {
        $entityType = $this->entityType();
        $repository = new FixtureWorkflowEntityRepository();
        $repository->addEntity(new FixtureWorkflowEntity('20', 'draft'));
        $workflow = $this->editorialWorkflow();
        $workflowRepository = new FixtureWorkflowLookupRepository($workflow);

        $entityTypeManager = new FixtureWorkflowEntityTypeManager($entityType, $repository, $workflowRepository);
        $configFactory = new FixtureAssignmentsConfigFactory([self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial']);
        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $transitionService = new TransitionService($bindings, $entityTypeManager);

        $accessHandler = new EntityAccessHandler([$this->workflowStateFieldDenyPolicy()]);
        $account = $this->account(9, ['use editorial transition submit_for_review']);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        $request = $this->requestWithAccount('GET', '/api/wf_article/20/workflow/transitions', $account);
        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '20');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertNull($body['meta']['workflow_state']);
        // The transition list itself is unaffected by the field gate — it
        // remains the entity-level view check + engine filtering's job.
        $this->assertCount(1, $body['data']);
        $this->assertSame('submit_for_review', $body['data'][0]['id']);
    }

    /**
     * Entity-level view allowed, but a field policy view-Forbids
     * `workflow_state` specifically. Anonymous class implementing BOTH
     * `AccessPolicyInterface` and `FieldAccessPolicyInterface` — PHPUnit
     * `createMock()` cannot mock an intersection type — mirrors
     * {@see \Waaseyaa\Api\Tests\Unit\ResourceSerializerFieldAccessTest::createViewDenyPolicy()}.
     */
    private function workflowStateFieldDenyPolicy(): AccessPolicyInterface
    {
        return new class () implements AccessPolicyInterface, \Waaseyaa\Access\FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'workflow_state' && $operation === 'view') {
                    return AccessResult::forbidden('No view access to workflow_state for testing.');
                }

                return AccessResult::neutral();
            }
        };
    }

    // --- POST transition ---

    #[Test]
    public function postTransitionSuccessReturnsTransitionShape(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('11', 'draft'));
        $account = $this->account(9, ['use editorial transition submit_for_review']);
        $request = $this->postRequest('/api/wf_article/11/workflow/transition', '{"transition":"submit_for_review"}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '11');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('submit_for_review', $body['data']['transition']);
        $this->assertSame('draft', $body['data']['from']);
        $this->assertSame('review', $body['data']['to']);
    }

    #[Test]
    public function postTransitionPermissionDeniedReturns403WithReasonMeta(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('12', 'draft'));
        $account = $this->account(9, []);
        $request = $this->postRequest('/api/wf_article/12/workflow/transition', '{"transition":"submit_for_review"}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '12');

        $this->assertSame(403, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('WORKFLOW_TRANSITION_DENIED', $body['errors'][0]['code']);
        $this->assertSame('permission', $body['errors'][0]['meta']['reason']);
    }

    #[Test]
    public function postTransitionIllegalEdgeReturns422WithReasonMeta(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        // 'submit_for_review' only fires from 'draft'; entity is in 'published'.
        $repository->addEntity(new FixtureWorkflowEntity('13', 'published'));
        $account = $this->account(9, ['use editorial transition submit_for_review']);
        $request = $this->postRequest('/api/wf_article/13/workflow/transition', '{"transition":"submit_for_review"}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '13');

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('WORKFLOW_TRANSITION_DENIED', $body['errors'][0]['code']);
        $this->assertSame('illegal_edge', $body['errors'][0]['meta']['reason']);
    }

    #[Test]
    public function postTransitionUnknownTransitionReturns422WithReasonMeta(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('14', 'draft'));
        $account = $this->account(9, []);
        $request = $this->postRequest('/api/wf_article/14/workflow/transition', '{"transition":"nonexistent"}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '14');

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('unknown_transition', $body['errors'][0]['meta']['reason']);
    }

    // --- 400 malformed body ---

    #[Test]
    public function postTransitionMalformedJsonReturns400(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('15', 'draft'));
        $account = $this->account(9, []);
        $request = $this->postRequest('/api/wf_article/15/workflow/transition', '{not json', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '15');

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function postTransitionMissingTransitionKeyReturns400(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('16', 'draft'));
        $account = $this->account(9, []);
        $request = $this->postRequest('/api/wf_article/16/workflow/transition', '{}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '16');

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function postTransitionNonStringTransitionReturns400(): void
    {
        [$controller, $repository] = $this->boundWorld(denyView: false);
        $repository->addEntity(new FixtureWorkflowEntity('17', 'draft'));
        $account = $this->account(9, []);
        $request = $this->postRequest('/api/wf_article/17/workflow/transition', '{"transition": 42}', $account);

        $response = $controller->transition($request, self::ENTITY_TYPE_ID, '17');

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- World builders ---

    /**
     * @return array{0: WorkflowTransitionController, 1: FixtureWorkflowEntityRepository, 2: TransitionService, 3: AccountInterface}
     */
    private function boundWorld(bool $denyView, array $permissions = []): array
    {
        $entityType = $this->entityType();
        $repository = new FixtureWorkflowEntityRepository();
        $workflow = $this->editorialWorkflow();
        $workflowRepository = new FixtureWorkflowLookupRepository($workflow);

        $entityTypeManager = new FixtureWorkflowEntityTypeManager($entityType, $repository, $workflowRepository);
        $configFactory = new FixtureAssignmentsConfigFactory([self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial']);
        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $transitionService = new TransitionService($bindings, $entityTypeManager);

        $accessHandler = new EntityAccessHandler([$this->accessPolicy($denyView)]);
        $account = $this->account(1, $permissions);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        return [$controller, $repository, $transitionService, $account];
    }

    /**
     * @return array{0: WorkflowTransitionController, 1: FixtureWorkflowEntityRepository}
     */
    private function unboundWorld(): array
    {
        $entityType = $this->entityType();
        $repository = new FixtureWorkflowEntityRepository();
        $workflowRepository = new FixtureWorkflowLookupRepository(null);

        $entityTypeManager = new FixtureWorkflowEntityTypeManager($entityType, $repository, $workflowRepository);
        // No assignments at all: entity type/bundle is unbound.
        $configFactory = new FixtureAssignmentsConfigFactory([]);
        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $transitionService = new TransitionService($bindings, $entityTypeManager);

        $accessHandler = new EntityAccessHandler([$this->accessPolicy(denyView: false)]);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        return [$controller, $repository];
    }

    private function entityType(): EntityType
    {
        return new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'WF Article',
            class: FixtureWorkflowEntity::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type', 'revision' => 'vid'],
            revisionable: true,
        );
    }

    private function editorialWorkflow(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published'],
            ],
        ]);
    }

    private function accessPolicy(bool $denyView): AccessPolicyInterface
    {
        return new class ($denyView) implements AccessPolicyInterface {
            public function __construct(private readonly bool $denyView) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view' && $this->denyView) {
                    return AccessResult::forbidden('No view access for testing.');
                }

                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };
    }

    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return ['authenticated']; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    private function requestWithAccount(string $method, string $uri, AccountInterface $account): Request
    {
        $request = Request::create($uri, $method);
        $request->attributes->set('_account', $account);

        return $request;
    }

    private function postRequest(string $uri, string $body, AccountInterface $account): Request
    {
        $request = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->attributes->set('_account', $account);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Minimal `EntityInterface` fixture carrying only what the controller and
 * `TransitionService` touch: id, `workflow_state`, `status`, a fixed bundle.
 */
final class FixtureWorkflowEntity implements EntityInterface
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(string $id, string $state = 'draft', int $status = 0)
    {
        $this->values = ['id' => $id, 'workflow_state' => $state, 'status' => $status];
    }

    public function id(): int|string|null { return $this->values['id']; }
    public function uuid(): string { return 'fixture-uuid-' . (string) $this->values['id']; }
    public function label(): string { return 'Fixture'; }
    public function getEntityTypeId(): string { return 'wf_article'; }
    public function bundle(): string { return 'wf_article'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}

/**
 * `EntityRepositoryInterface` fixture supporting exactly what the controller
 * and `TransitionService` need: `find()` and `save()` over an in-memory map,
 * plus a `loadPublishedRevision()` that returns null (rather than throwing,
 * unlike the shared `InMemoryEntityRepository` fixture) — `TransitionService`
 * calls it unconditionally, even for a non-revisionable-in-practice fixture.
 */
final class FixtureWorkflowEntityRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $entities = [];

    public function addEntity(EntityInterface $entity): void
    {
        $this->entities[(string) $entity->id()] = $entity;
    }

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->entities[(string) $entity->id()] = $entity;

        return 2;
    }

    public function delete(EntityInterface $entity): void { unset($this->entities[(string) $entity->id()]); }
    public function exists(string $id): bool { return isset($this->entities[$id]); }
    public function count(array $criteria = []): int { return \count($this->entities); }
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

/**
 * Stub for `getRepository('workflow')` — `WorkflowBindingResolver::resolve()`
 * calls `->find($workflowId)` on it.
 */
final class FixtureWorkflowLookupRepository implements EntityRepositoryInterface
{
    public function __construct(private readonly ?Workflow $workflow) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
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

final class FixtureWorkflowEntityTypeManager implements EntityTypeManagerInterface
{
    public function __construct(
        private readonly EntityType $entityType,
        private readonly FixtureWorkflowEntityRepository $repository,
        private readonly FixtureWorkflowLookupRepository $workflowRepository,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface { return $this->entityType; }
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
    public function getDefinitions(): array { return [$this->entityType->id() => $this->entityType]; }
    public function hasDefinition(string $entityTypeId): bool { return $entityTypeId === $this->entityType->id(); }
    public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface { throw new \LogicException('not needed'); }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $entityTypeId === 'workflow' ? $this->workflowRepository : $this->repository;
    }
}

final class FixtureAssignmentsConfigFactory implements ConfigFactoryInterface
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
