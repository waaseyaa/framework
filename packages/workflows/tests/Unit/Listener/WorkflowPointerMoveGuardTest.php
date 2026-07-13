<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard
 */
#[CoversClass(WorkflowPointerMoveGuard::class)]
final class WorkflowPointerMoveGuardTest extends TestCase
{
    private function editorialWorkflow(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
                'archived' => ['label' => 'Archived', 'published' => false, 'default_revision' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published'],
                'archive' => ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived'],
                'restore' => ['label' => 'Restore to draft', 'from' => ['archived'], 'to' => 'draft'],
                // Mirrors the production DefaultWorkflows 'restore_to_published'
                // edge (task 2.6 re-review): archived content is republishable.
                'restore_to_published' => ['label' => 'Restore', 'from' => ['archived'], 'to' => 'published'],
            ],
        ]);
    }

    /**
     * @param array<int, ?string> $revisionStates revisionId => workflow_state (absent key => missing revision)
     */
    private function entityTypeManager(?Workflow $workflow, array $revisionStates = []): EntityTypeManagerInterface
    {
        return new class ($workflow, $revisionStates) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private readonly array $revisionStates,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(
                    id: 'fixture',
                    label: 'Fixture',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'bundle' => 'type', 'revision' => 'vid'],
                    revisionable: true,
                );
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflow = $this->workflow;
                $revisionStates = $this->revisionStates;

                return new class ($workflow, $revisionStates) implements EntityRepositoryInterface {
                    public function __construct(
                        private readonly ?Workflow $workflow,
                        private readonly array $revisionStates,
                    ) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
                    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return true; }
                    public function count(array $criteria = []): int { return 0; }

                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
                    {
                        if (!\array_key_exists($revisionId, $this->revisionStates)) {
                            return null;
                        }

                        $state = $this->revisionStates[$revisionId];

                        return new class ($state) implements EntityInterface {
                            public function __construct(private readonly ?string $state) {}
                            public function id(): int|string|null { return 1; }
                            public function uuid(): string { return 'u-1'; }
                            public function label(): string { return 'Fixture'; }
                            public function getEntityTypeId(): string { return 'fixture'; }
                            public function bundle(): string { return 'article'; }
                            public function isNew(): bool { return false; }
                            public function get(string $name): mixed { return $name === 'workflow_state' ? $this->state : null; }
                            public function set(string $name, mixed $value): static { return $this; }
                            public function toArray(): array { return []; }
                            public function language(): string { return 'en'; }
                        };
                    }

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

    private function bindings(?Workflow $workflow, EntityTypeManagerInterface $entityTypeManager): WorkflowBindingResolver
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

        return new WorkflowBindingResolver($configFactory, $entityTypeManager);
    }

    private function accountContext(?AccountInterface $account): AccountContextInterface
    {
        return new class ($account) implements AccountContextInterface {
            public function __construct(private readonly ?AccountInterface $account) {}
            public function current(): ?AccountInterface { return $this->account; }
            public function set(?AccountInterface $account): void {}
        };
    }

    private function account(array $permissions): AccountInterface
    {
        return new class ($permissions) implements AccountInterface {
            public function __construct(private readonly array $permissions) {}
            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    /**
     * @param array<int, ?string> $revisionStates
     */
    private function guard(?Workflow $workflow, array $revisionStates, ?AccountInterface $account): WorkflowPointerMoveGuard
    {
        $entityTypeManager = $this->entityTypeManager($workflow, $revisionStates);

        return new WorkflowPointerMoveGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($account),
        );
    }

    #[Test]
    public function unbound_entity_type_is_untouched(): void
    {
        $guard = $this->guard(null, [], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 5,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'nonexistent'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function allowed_edge_passes(): void
    {
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function missing_edge_is_denied(): void
    {
        // 'published' -> 'review' has no transition in the fixture workflow.
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'review'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function permission_denied_with_account(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function null_account_context_checks_edge_legality_only(): void
    {
        $entityTypeManager = $this->entityTypeManager($this->editorialWorkflow(), [10 => 'draft']);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($this->editorialWorkflow(), $entityTypeManager),
            $entityTypeManager,
            null,
        );
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: null,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function translation_save_is_always_a_pass_through(): void
    {
        // Even with an implied illegal edge and no permission, translation_save
        // never validates a transition — v1 workflow_state is per revision, not
        // per revision-translation (docs/specs/content-workflow.md).
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'translation_save',
            fromRevisionId: 10,
            toRevisionId: null,
            actorUid: 7,
            revisionValues: ['some_field' => 'translated value'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function null_from_revision_id_falls_back_to_the_initial_state(): void
    {
        // 'publish' with fromRevisionId null (previously unpublished): the
        // currently-effective state falls back to the workflow's initial state.
        $guard = $this->guard($this->editorialWorkflow(), [], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function same_state_pointer_move_with_a_null_account_context_is_allowed(): void
    {
        // Same-state move (draft -> draft): the workflow state does not
        // change — only which revision serves. No edge required; with no
        // acting account context there is no permission to prove either.
        $entityTypeManager = $this->entityTypeManager($this->editorialWorkflow(), [10 => 'draft']);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($this->editorialWorkflow(), $entityTypeManager),
            $entityTypeManager,
            null,
        );
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: null,
            revisionValues: ['type' => 'article', 'workflow_state' => 'draft'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function same_state_pointer_move_without_any_transition_into_state_permission_is_denied(): void
    {
        // CW-v1 WP-2 task 2.6 fix (#1920): a same-state pointer move is
        // state-legal without an edge, but with an acting account it still
        // requires the permission of AT LEAST ONE transition targeting that
        // state (any-of). The only transition into 'draft' here is
        // 'restore'; the account holds nothing.
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'draft'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function same_state_promotion_is_allowed_with_a_transition_into_state_permission(): void
    {
        // The sanctioned forward-draft two-step's guard-visible shape:
        // pointer sits on a 'published' revision, target revision is also
        // 'published' (TransitionService just saved it). Same-state — no
        // edge required; account holds the permission of a transition into
        // 'published' ('publish') — allowed.
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function same_state_promotion_without_the_permission_is_denied(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function rollback_to_a_published_revision_is_allowed_with_the_publish_permission(): void
    {
        // The same-state allowance applies uniformly to all pointer
        // operations, not just 'publish': rolling the current pointer back
        // to an earlier 'published'-stamped revision while the current
        // revision is also 'published' is a same-state move (Task 2.8's
        // spine relies on this — 'published' -> 'published' has no edge, so
        // strict-only would always deny it).
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'rollback',
            fromRevisionId: 10,
            toRevisionId: null,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function publish_promoting_an_archived_pointer_is_allowed_with_the_restore_to_published_permission(): void
    {
        // CW-v1 WP-2 task 2.6 re-review (#1920): archived -> published is a
        // DIFFERENT-state move, so the strict rule applies — and the
        // 'restore_to_published' edge now exists (Drupal editorial parity),
        // so an account holding THAT edge's permission may promote.
        $account = $this->account(['use editorial transition restore_to_published']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'archived'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function publish_promoting_an_archived_pointer_without_the_restore_to_published_permission_is_denied(): void
    {
        // CW-v1 WP-2 task 2.6 fix (#1920): a DIFFERENT-state pointer move
        // gets the strict rule, no exceptions — the currentState ->
        // newState edge's OWN permission is required. Pointer sits on
        // 'archived'; promoting a 'published'-stamped revision implies
        // archived -> published, whose edge is 'restore_to_published'.
        // Holding only the 'publish' permission does not help: an earlier
        // revision of this task allowed exactly this via an
        // any-transition-into-state fallback, which reopened the bypass
        // (resurrecting old published content out of 'archived' with only
        // the publish permission). REASON_PERMISSION, not ILLEGAL_EDGE —
        // the edge exists now; the account just may not use it.
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'archived'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function different_state_move_with_a_real_edge_still_enforces_that_edges_permission(): void
    {
        // archived -> draft has a real edge ('restore'); the account holds
        // only 'publish'. The strict rule enforces THE EDGE'S permission on
        // the different-state path — no any-of fallback applies there.
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'archived'], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'draft'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    private function workflowWithGroupConstraint(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
            ],
        ]);
    }

    #[Test]
    public function a_null_checker_denies_a_different_state_move_across_a_constrained_edge_fail_closed(): void
    {
        // Adversarial-review fix (#1920, WP-3): a null groupConstraintChecker
        // used to mean "no group gating" — satisfiesGroupConstraint()
        // returned true unconditionally. It must fail closed when an
        // account context exists, same as TransitionService/WorkflowStateGuard.
        $workflow = $this->workflowWithGroupConstraint();
        $entityTypeManager = $this->entityTypeManager($workflow, [10 => 'draft']);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($this->account(['use editorial transition publish'])),
        );
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $e->reason);
        }
    }

    #[Test]
    public function publish_op_targeting_a_non_default_revision_state_uses_the_strict_edge_check(): void
    {
        // A 'publish'-operation pointer move pointed at a 'draft' revision
        // (a bypass-style setPublishedRevision() call) is a different-state
        // move (review -> draft) with no edge in this fixture — denied.
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'review'], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'draft'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }
}
