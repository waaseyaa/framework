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
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4 item 1): the WORKFLOW POSITION
 * (`meta.workflow_state`, the offered transitions, and the POST transition
 * target) must be resolved from `loadWorkingCopy()` — the TIP — not from the
 * `find()`-loaded, R8-gated entity, which under default-revision discipline
 * always reports the PUBLISHED pointer's state while a forward draft is in
 * flight. Reuses the fixture infrastructure from
 * {@see WorkflowTransitionControllerRevisionConflictTest} (same namespace,
 * same directory): a repository whose `find()` and `loadWorkingCopy()`
 * deliberately diverge is the exact "gate entity vs. working copy" shape
 * this PR introduces.
 *
 * @covers \Waaseyaa\Api\Controller\WorkflowTransitionController
 */
#[CoversClass(WorkflowTransitionController::class)]
final class WorkflowTransitionControllerWorkingCopyTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'wf_conflict_article';

    #[Test]
    public function get_transitions_reports_the_tip_state_not_the_published_pointer_state(): void
    {
        // find() (the R8 view gate + gate entity) reports 'published' — the
        // served content. loadWorkingCopy() (the tip) reports 'review' — a
        // forward draft mid-review. Only 'review' has an outgoing transition
        // in this fixture workflow, so a bug that sources the position from
        // find() would report 'published' with ZERO available transitions.
        $gateEntity = new ConflictFixtureEntity('1', revisionId: 3, state: 'published');
        $workingCopy = new ConflictFixtureEntity('1', revisionId: 9, state: 'review');

        [$controller, $request] = $this->boot($gateEntity, $workingCopy, permissions: ['use editorial transition publish']);

        $response = $controller->transitions($request, self::ENTITY_TYPE_ID, '1');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('review', $body['meta']['workflow_state'], 'meta.workflow_state must come from the working copy (tip), not the served/published entity.');
        $available = array_column($body['data'], 'id');
        $this->assertContains('publish', $available, 'The available-transitions list must be computed from the tip state (review -> published is legal), not the published state (which has no outgoing edge in this fixture).');
    }

    #[Test]
    public function post_transition_fires_against_the_tip(): void
    {
        $gateEntity = new ConflictFixtureEntity('1', revisionId: 3, state: 'published');
        $workingCopy = new ConflictFixtureEntity('1', revisionId: 9, state: 'review');

        [$controller, , $repository] = $this->boot($gateEntity, $workingCopy, permissions: ['use editorial transition publish']);

        $postRequest = Request::create(
            '/api/' . self::ENTITY_TYPE_ID . '/1/workflow/transition',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['transition' => 'publish'], JSON_THROW_ON_ERROR),
        );
        $postRequest->attributes->set('_account', $this->account(['use editorial transition publish']));

        $response = $controller->transition($postRequest, self::ENTITY_TYPE_ID, '1');

        $this->assertSame(200, $response->getStatusCode(), 'Firing "publish" against the TIP (review) must succeed: ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('review', $body['data']['from'], 'The transition must have fired from the TIP state (review), not the published-pointer state.');
        $this->assertSame('published', $body['data']['to']);
        $this->assertSame($workingCopy, $repository->savedEntity, 'save() must persist through the WORKING COPY object, not the gate entity.');
    }

    /**
     * @param list<string> $permissions
     * @return array{0: WorkflowTransitionController, 1: Request, 2: WorkingCopyDivergentRepository}
     */
    private function boot(ConflictFixtureEntity $gateEntity, ConflictFixtureEntity $workingCopy, array $permissions): array
    {
        $repository = new WorkingCopyDivergentRepository($gateEntity, $workingCopy);
        $entityType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'WF Conflict Article',
            class: ConflictFixtureEntity::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type', 'revision' => 'vid'],
            revisionable: true,
        );
        $workflow = new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'default_revision' => true, 'published' => true],
            ],
            'transitions' => [
                'publish' => ['label' => 'Publish', 'from' => ['review'], 'to' => 'published', 'permission' => 'use editorial transition publish'],
            ],
        ]);
        $workflowRepository = new ConflictFixtureWorkflowLookupRepository($workflow);
        $entityTypeManager = new ConflictFixtureEntityTypeManager($entityType, $repository, $workflowRepository);
        $configFactory = new ConflictFixtureConfigFactory([self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial']);
        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $transitionService = new TransitionService($bindings, $entityTypeManager);

        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $account = $this->account($permissions);
        $controller = new WorkflowTransitionController($entityTypeManager, $accessHandler, $transitionService);

        $request = Request::create('/api/' . self::ENTITY_TYPE_ID . '/1/workflow/transitions', 'GET');
        $request->attributes->set('_account', $account);

        return [$controller, $request, $repository];
    }

    private function allowAllPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
        };
    }

    /**
     * @param list<string> $permissions
     */
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

/**
 * Same shape as {@see ConflictFixtureRepository}, plus a `save()` that
 * actually records what it was called with (needed to prove the POST
 * transition's save lands on the WORKING COPY object, not the gate entity).
 */
final class WorkingCopyDivergentRepository implements \Waaseyaa\Entity\Repository\EntityRepositoryInterface
{
    public ?EntityInterface $savedEntity = null;

    public function __construct(
        private readonly ConflictFixtureEntity $gateEntity,
        private readonly ConflictFixtureEntity $workingCopy,
    ) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->gateEntity; }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->workingCopy; }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }

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

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        // TransitionService's promote branch calls this after the
        // revision-creating save when the target state is default_revision.
        // Returning the working copy (its content already carries the new
        // state) is enough for this fixture's purposes.
        return $this->workingCopy;
    }

    public function saveMany(array $entities, bool $validate = true): array { return []; }
    public function deleteMany(array $entities): int { return 0; }
    public function findTranslations(EntityInterface $entity): array { return []; }
    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int { return 0; }
    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
}
