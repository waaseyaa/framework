<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Transition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Event\WorkflowEvents;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Transition\TransitionService
 */
#[CoversClass(TransitionService::class)]
final class TransitionServiceTest extends TestCase
{
    private function editorialWorkflow(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published'],
                // Mirrors the production DefaultWorkflows 'reject' edge:
                // exercises a forward-draft transition away from a
                // defaultRevision:false state on already-published content.
                'reject' => ['label' => 'Reject', 'from' => ['review'], 'to' => 'draft'],
            ],
        ]);
    }

    private function bindings(?Workflow $workflow, ?EntityTypeManagerInterface $entityTypeManager = null): WorkflowBindingResolver
    {
        $configFactory = new class ($workflow) implements ConfigFactoryInterface {
            public function __construct(private readonly ?Workflow $workflow) {}

            public function get(string $name): ConfigInterface
            {
                $data = $this->workflow !== null ? ['fixture.article' => 'editorial'] : [];

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
        };

        return new WorkflowBindingResolver($configFactory, $entityTypeManager ?? $this->entityTypeManager());
    }

    private ?Workflow $workflowForBinding = null;

    /** @var list<int> tracks how many times save() was called on the repository spy */
    private array $saveCalls = [];

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $workflow = $this->workflowForBinding;
        $saveCallsRef = &$this->saveCalls;

        return new class ($workflow, $saveCallsRef) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private array &$saveCalls,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(
                    id: 'fixture',
                    label: 'Fixture',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'revision' => 'vid'],
                    revisionable: true,
                );
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                if ($entityTypeId === 'workflow') {
                    return new WorkflowLookupRepository($this->workflow);
                }

                return new SpyEntityRepository($this->saveCalls);
            }
        };
    }

    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return $this->accountId !== 0; }
        };
    }

    private function entity(string $state = 'draft'): EntityInterface
    {
        return new class ($state) implements EntityInterface {
            private array $values;

            public function __construct(string $state)
            {
                $this->values = ['id' => 1, 'workflow_state' => $state, 'status' => 0];
            }

            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'fixture-uuid'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
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
     * A revisionable fixture entity: implements `RevisionableInterface` so
     * `TransitionService` exercises its `setNewRevision()`/`getRevisionId()`
     * duck-checked path. `revision_id` rides in the generic `$values` bag
     * (same as production `RevisionableEntityTrait`), so the repository spy
     * below can simulate revision-id assignment via the ordinary
     * `EntityInterface::set()` surface — no bespoke setter needed.
     */
    private function revisionableEntity(string $state, int $status = 0): EntityInterface
    {
        return new class ($state, $status) implements EntityInterface, RevisionableInterface {
            private array $values;
            private ?bool $newRevisionOverride = null;
            private ?string $revisionLog = null;

            public function __construct(string $state, int $status)
            {
                $this->values = ['id' => 1, 'workflow_state' => $state, 'status' => $status];
            }

            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'fixture-uuid'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }

            public function set(string $name, mixed $value): static
            {
                $this->values[$name] = $value;

                return $this;
            }

            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }

            public function getRevisionId(): ?int
            {
                $rid = $this->values['revision_id'] ?? null;

                return \is_int($rid) ? $rid : null;
            }

            public function isDefaultRevision(): bool { return true; }
            public function isLatestRevision(): bool { return true; }
            public function setNewRevision(bool $value): void { $this->newRevisionOverride = $value; }
            public function isNewRevision(): ?bool { return $this->newRevisionOverride; }
            public function setRevisionLog(?string $log): void { $this->revisionLog = $log; }
            public function getRevisionLog(): ?string { return $this->revisionLog; }
        };
    }

    private function spyDispatcher(): SpyDispatcher
    {
        return new SpyDispatcher();
    }

    private function spyAuditWriter(): SpyAuditWriter
    {
        return new SpyAuditWriter();
    }

    private function service(Workflow $workflow, ?EventDispatcherInterface $dispatcher = null, ?AuditWriterInterface $auditWriter = null): TransitionService
    {
        $this->workflowForBinding = $workflow;
        $this->saveCalls = [];

        return new TransitionService(
            bindings: $this->bindings($workflow),
            entityTypeManager: $this->entityTypeManager(),
            dispatcher: $dispatcher,
            auditWriter: $auditWriter,
        );
    }

    /**
     * Builds a service wired to a caller-supplied 'fixture' repository (the
     * {@see RevisionAwareSpyRepository} below), for tests that need to
     * observe/configure revision-id assignment, `setPublishedRevision()`
     * calls, and `loadPublishedRevision()` fixtures — the plain
     * {@see SpyEntityRepository} used by `service()` doesn't simulate any of
     * that.
     */
    private function serviceWithRepository(Workflow $workflow, EntityRepositoryInterface $repository, ?\Waaseyaa\Foundation\Log\LoggerInterface $logger = null): TransitionService
    {
        $workflowRepository = new WorkflowLookupRepository($workflow);
        $entityTypeManager = new class ($workflowRepository, $repository) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly EntityRepositoryInterface $workflowRepository,
                private readonly EntityRepositoryInterface $fixtureRepository,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(
                    id: 'fixture',
                    label: 'Fixture',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'revision' => 'revision_id'],
                    revisionable: true,
                );
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                return $entityTypeId === 'workflow' ? $this->workflowRepository : $this->fixtureRepository;
            }
        };

        return new TransitionService(
            bindings: $this->bindings($workflow, $entityTypeManager),
            entityTypeManager: $entityTypeManager,
            logger: $logger,
        );
    }

    #[Test]
    public function a_permitted_transition_applies_state_and_status_and_saves(): void
    {
        $workflow = $this->editorialWorkflow();
        $dispatcher = $this->spyDispatcher();
        $auditWriter = $this->spyAuditWriter();
        $service = $this->service($workflow, $dispatcher, $auditWriter);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->entity('draft');

        $result = $service->transition($entity, 'publish', $account);

        $this->assertSame('draft', $result->fromState);
        $this->assertSame('published', $result->toState);
        $this->assertSame('publish', $result->transitionId);
        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame(['pre', 'post'], $dispatcher->firedNames());
        $this->assertCount(1, $auditWriter->recorded);
        $this->assertSame('allowed', $auditWriter->recorded[0]->outcome);
    }

    #[Test]
    public function unbound_entity_denies_with_reason_unbound(): void
    {
        // No workflow registered for this type/bundle => unbound.
        $this->workflowForBinding = null;
        $this->saveCalls = [];
        $service = new TransitionService($this->bindings(null), $this->entityTypeManager());
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_UNBOUND, $e->reason);
        }
    }

    #[Test]
    public function unknown_transition_denies_with_reason_unknown_transition(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'nonexistent', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_UNKNOWN_TRANSITION, $e->reason);
        }
    }

    #[Test]
    public function wrong_from_state_denies_with_reason_illegal_edge(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, ['use editorial transition submit_for_review']);
        // 'submit_for_review' only fires from 'draft'; entity is in 'published'.
        $entity = $this->entity('published');

        try {
            $service->transition($entity, 'submit_for_review', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function missing_permission_denies_with_reason_permission(): void
    {
        $auditWriter = $this->spyAuditWriter();
        $service = $this->service($this->editorialWorkflow(), null, $auditWriter);
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }

        $this->assertCount(1, $auditWriter->recorded);
        $this->assertSame('denied', $auditWriter->recorded[0]->outcome);
    }

    #[Test]
    public function a_denied_transition_never_saves_or_dispatches_post(): void
    {
        $dispatcher = $this->spyDispatcher();
        $service = $this->service($this->editorialWorkflow(), $dispatcher);
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
        } catch (TransitionDeniedException) {
            // expected
        }

        $this->assertSame([], $dispatcher->firedNames());
        $this->assertSame('draft', $entity->get('workflow_state'));
    }

    #[Test]
    public function get_available_transitions_filters_by_from_state_and_permission(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, ['use editorial transition submit_for_review']);
        $entity = $this->entity('draft');

        $available = $service->getAvailableTransitions($entity, $account);

        $this->assertCount(1, $available);
        $this->assertSame('submit_for_review', $available[0]->id);
    }

    #[Test]
    public function get_available_transitions_is_empty_for_an_unbound_entity(): void
    {
        $service = new TransitionService($this->bindings(null), $this->entityTypeManager());
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->entity('draft');

        $this->assertSame([], $service->getAvailableTransitions($entity, $account));
    }

    private function workflowWithGroupConstraint(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
            ],
        ]);
    }

    #[Test]
    public function a_null_checker_denies_a_group_constrained_transition_fail_closed(): void
    {
        // Adversarial-review fix (#1920, WP-3): a null groupConstraintChecker
        // used to mean "no group gating" (fail-open) for a
        // group_constrained transition. It now must fail closed — a wiring
        // regression (production provider not injecting the checker) denies
        // loudly instead of silently un-gating.
        $service = $this->service($this->workflowWithGroupConstraint());
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $e->reason);
        }
    }

    #[Test]
    public function a_null_checker_leaves_a_constraint_less_transition_unaffected(): void
    {
        $service = $this->service($this->workflowWithGroupConstraint());
        $account = $this->account(7, ['use editorial transition submit_for_review']);
        $entity = $this->entity('draft');

        $result = $service->transition($entity, 'submit_for_review', $account);

        $this->assertSame('review', $result->toState);
    }

    #[Test]
    public function get_available_transitions_with_a_null_checker_filters_out_the_group_constrained_transition(): void
    {
        $service = $this->service($this->workflowWithGroupConstraint());
        $account = $this->account(7, ['use editorial transition submit_for_review', 'use editorial transition publish']);
        $entity = $this->entity('draft');

        $available = \array_map(static fn($t) => $t->id, $service->getAvailableTransitions($entity, $account));

        $this->assertSame(['submit_for_review'], $available);
    }

    #[Test]
    public function forward_draft_leaves_status_and_the_published_pointer_untouched(): void
    {
        // CW-v1 WP-2 task 2.6 (#1920, two-pointer status semantics): the
        // target state ('draft') is `default_revision: false`, and a
        // DIFFERENT revision is already the published pointer (status 1) —
        // this is a forward draft. The new tip must carry the target state,
        // but `status` must keep reflecting the *published* revision (1),
        // and the pointer must never move.
        $publishedRevision = $this->entity('published');
        $publishedRevision->set('status', 1);
        $repository = new RevisionAwareSpyRepository(publishedRevision: $publishedRevision);
        $service = $this->serviceWithRepository($this->editorialWorkflow(), $repository);
        $account = $this->account(7, ['use editorial transition reject']);
        $entity = $this->revisionableEntity('review', status: 0);

        $result = $service->transition($entity, 'reject', $account);

        $this->assertSame('review', $result->fromState);
        $this->assertSame('draft', $result->toState);
        $this->assertSame('draft', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame([], $repository->publishedCalls);
        $this->assertCount(1, $repository->saveCalls);
        $this->assertTrue($entity->isNewRevision());
    }

    #[Test]
    public function first_ever_publish_establishes_the_pointer_and_flips_status(): void
    {
        // No published revision exists yet: `defaultRevision: true` still
        // unconditionally moves/establishes the pointer (this is how the
        // two-pointer mechanism activates in the first place) — decision
        // 2's "never published" bullet describes the STATUS outcome
        // (follows state directly), not a suppressed pointer move.
        $repository = new RevisionAwareSpyRepository(publishedRevision: null, firstRevisionId: 10);
        $service = $this->serviceWithRepository($this->editorialWorkflow(), $repository);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->revisionableEntity('draft', status: 0);

        $service->transition($entity, 'publish', $account);

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame([['1', 10]], $repository->publishedCalls);
    }

    #[Test]
    public function promoting_a_forward_draft_moves_the_pointer_then_flips_status(): void
    {
        $olderPublished = $this->entity('published');
        $olderPublished->set('status', 1);
        $repository = new RevisionAwareSpyRepository(publishedRevision: $olderPublished, firstRevisionId: 10);
        $service = $this->serviceWithRepository($this->editorialWorkflow(), $repository);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->revisionableEntity('review', status: 0);

        $service->transition($entity, 'publish', $account);

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame([['1', 10]], $repository->publishedCalls);
        // Revision-creating save + the follow-up status-only save.
        $this->assertCount(2, $repository->saveCalls);
    }

    #[Test]
    public function a_denied_pointer_move_never_flips_status(): void
    {
        // Safe-ordering requirement (task 2.6): if the pointer-move guard
        // denies the promotion (simulated here via a repository that throws
        // from setPublishedRevision(), the same seam WorkflowPointerMoveGuard
        // uses), `status` must NOT have been flipped while the pointer stays
        // put — never "status says live, pointer says otherwise."
        $denial = new TransitionDeniedException(TransitionDeniedException::REASON_ILLEGAL_EDGE, 'simulated pointer-move denial');
        $repository = new RevisionAwareSpyRepository(publishedRevision: null, firstRevisionId: 10, throwOnPublish: $denial);
        $service = $this->serviceWithRepository($this->editorialWorkflow(), $repository);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->revisionableEntity('review', status: 0);

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame($denial, $e);
        }

        // The revision-creating save happened (its own workflow_state is
        // legitimately 'published' — it just hasn't been promoted), but the
        // status-flip follow-up save never ran.
        $this->assertSame(0, $entity->get('status'));
        $this->assertCount(1, $repository->saveCalls);
        $this->assertSame([['1', 10]], $repository->publishedCalls);
    }

    #[Test]
    public function a_revisionable_entity_with_no_revision_id_after_save_logs_a_warning_and_keeps_wp1_behavior(): void
    {
        // MINOR-4 fix (task 2.6 review): a revisionable entity whose save
        // did not hand a revision id back is a storage/hydration defect,
        // not the benign non-revisionable case — the pointer cannot be
        // moved, WP-1 behavior (direct status flip) applies, and a warning
        // must say so instead of masking the missed promotion.
        $repository = new NoRevisionIdSpyRepository();
        $logger = new SpyWorkflowLogger();
        $service = $this->serviceWithRepository($this->editorialWorkflow(), $repository, $logger);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->revisionableEntity('draft', status: 0);

        $service->transition($entity, 'publish', $account);

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame([], $repository->publishedCalls);
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('workflows.transition_missing_revision_id', $logger->warnings[0]['message']);
    }
}

/**
 * Stub for the 'workflow' entity type's repository — production
 * WorkflowBindingResolver::resolve() calls getRepository('workflow')->find(),
 * never getStorage() (see WorkflowBindingResolver's deviation note).
 */
final class WorkflowLookupRepository implements EntityRepositoryInterface
{
    public function __construct(private readonly ?Workflow $workflow) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }
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

final class SpyDispatcher implements EventDispatcherInterface
{
    /** @var list<string> */
    private array $fired = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->fired[] = $eventName === WorkflowEvents::PRE_TRANSITION->value ? 'pre' : 'post';

        return $event;
    }

    /** @return list<string> */
    public function firedNames(): array
    {
        return $this->fired;
    }
}

final class SpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

/**
 * A revision-aware repository spy for the forward-draft/pointer-promotion
 * tests (CW-v1 WP-2 task 2.6). Simulates revision-id assignment on `save()`
 * (mirroring `EntityRepository::doSave()`'s `$entity->set($revisionKey, ...)`
 * after an insert), and records/optionally throws from
 * `setPublishedRevision()` so tests can assert the safe-ordering contract
 * (status only flips AFTER the pointer move succeeds).
 */
final class RevisionAwareSpyRepository implements EntityRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $saveCalls = [];

    /** @var list<array{0: string, 1: int}> */
    public array $publishedCalls = [];

    private int $nextRevisionId;

    public function __construct(
        private readonly ?EntityInterface $publishedRevision = null,
        int $firstRevisionId = 10,
        private readonly ?TransitionDeniedException $throwOnPublish = null,
    ) {
        $this->nextRevisionId = $firstRevisionId;
    }

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { throw new \LogicException('not needed'); }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $newRevisionId = $this->nextRevisionId++;
        $this->saveCalls[] = $entity->toArray();
        $entity->set('revision_id', $newRevisionId);

        return $newRevisionId;
    }

    public function delete(EntityInterface $entity): void {}
    public function exists(string $id): bool { return true; }
    public function count(array $criteria = []): int { return 0; }
    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
    public function rollback(string $entityId, int $targetRevisionId): EntityInterface { throw new \LogicException('not needed'); }
    public function listRevisions(string $entityId): array { return []; }
    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
    public function loadPublishedRevision(string $entityId): ?EntityInterface { return $this->publishedRevision; }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        $this->publishedCalls[] = [$entityId, $revisionId];

        if ($this->throwOnPublish !== null) {
            throw $this->throwOnPublish;
        }

        return $this->publishedRevision ?? new class implements EntityInterface {
            public function id(): int|string|null { return null; }
            public function uuid(): string { return ''; }
            public function label(): string { return ''; }
            public function getEntityTypeId(): string { return ''; }
            public function bundle(): string { return ''; }
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
}

/**
 * Simulates the MINOR-4 defect seam: a revisionable entity type whose
 * `save()` never hands the new revision id back to the entity, so
 * `TransitionService` cannot move the published pointer and must log a
 * warning instead of silently skipping the promotion.
 */
final class NoRevisionIdSpyRepository implements EntityRepositoryInterface
{
    /** @var list<array{0: string, 1: int}> */
    public array $publishedCalls = [];

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { throw new \LogicException('not needed'); }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        // Deliberately does NOT set revision_id on the entity.
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
        $this->publishedCalls[] = [$entityId, $revisionId];

        throw new \LogicException('must not be reached: no revision id was available');
    }

    public function saveMany(array $entities, bool $validate = true): array { return []; }
    public function deleteMany(array $entities): int { return 0; }
    public function findTranslations(EntityInterface $entity): array { return []; }
    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int { return 0; }
    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
}

final class SpyWorkflowLogger implements \Waaseyaa\Foundation\Log\LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void {}

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = ['message' => (string) $message, 'context' => $context];
    }

    public function notice(string|\Stringable $message, array $context = []): void {}

    public function info(string|\Stringable $message, array $context = []): void {}

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void {}
}

final class SpyEntityRepository implements EntityRepositoryInterface
{
    public int $saveCount = 0;

    public function __construct(private array &$saveCalls) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { throw new \LogicException('not needed'); }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->saveCalls[] = 1;
        $this->saveCount++;

        return 2;
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
