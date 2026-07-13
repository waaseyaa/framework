<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Listener\WorkflowRepublishListener;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Republish\RepublishMarker;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Validation\WorkflowValidator;

final class WorkflowServiceProvider extends ServiceProvider
{
    /**
     * Bundle sentinel for the framework-default `AuthoringRoleMatrix` binding.
     *
     * The matrix's row-emission contract (see {@see AuthoringRoleMatrix::forWorkflow()})
     * cross-products bundles × transitions, so a non-empty bundle list is
     * required to surface any guard rows. The framework does not know which
     * content bundles a host application has registered, so a generic `*`
     * sentinel stands in for "applies to all bundles" in the Phase 1 read-only
     * surface. Applications that want bundle-specific rows can rebind
     * {@see AuthoringRoleMatrix} in their own service provider; rebinding wins
     * because container resolution is last-write-wins per abstract id.
     *
     * Phase 2 (M4A-5b / #1579) will replace this with a repository-backed read
     * once persistence lands; the binding shape stays the same so consumers do
     * not need to change.
     */
    private const string DEFAULT_BUNDLE_SENTINEL = '*';

    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            description: 'State machines for content publication workflows',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        // M4A-5 Phase 1: bind `AuthoringRoleMatrix` seeded with the framework's
        // editorial workflow guards so the admin dashboard surface (the
        // `WorkflowGuardsController` wired by `ApiServiceProvider::routers()`)
        // returns non-empty data on a default boot. Without this binding, the
        // API controller is dead code in production — the cycle-1 review gap
        // this binding closes.
        //
        // Single source of truth: we re-derive the per-transition role lists
        // from {@see EditorialTransitionAccessResolver::allowedRolesForTransition()}
        // by iterating the editorial preset's transitions. That keeps the
        // canonical role matrix where the access resolver already owns it (no
        // duplicate constant), while letting this provider expose it via the
        // matrix's `workflowGuards` constructor arg.
        $this->singleton(AuthoringRoleMatrix::class, static function (): AuthoringRoleMatrix {
            $editorial = EditorialWorkflowPreset::create();
            $resolver = new EditorialTransitionAccessResolver($editorial);

            $guards = [];
            foreach ($editorial->getTransitions() as $transition) {
                $roles = $resolver->allowedRolesForTransition($transition->id);
                if ($roles === []) {
                    continue;
                }
                $guards[$transition->id] = $roles;
            }

            return new AuthoringRoleMatrix(
                bundles: [self::DEFAULT_BUNDLE_SENTINEL],
                roles: [],
                workflowGuards: [
                    (string) $editorial->id() => $guards,
                ],
            );
        });

        // CW-v1 WP-1 (#1920, docs/specs/content-workflow.md): engine core
        // bindings. Each factory resolves its own dependencies from the
        // container lazily (only when something actually asks for the
        // abstract), so a host application that has not yet wired
        // ConfigFactoryInterface into its container (see boot() note below)
        // pays no cost until code explicitly resolves one of these.
        $this->singleton(WorkflowBindingResolver::class, function (): WorkflowBindingResolver {
            return new WorkflowBindingResolver(
                configFactory: $this->resolve(ConfigFactoryInterface::class),
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
            );
        });

        // CW-v1 WP-3 (#1920): group-constraint gate. Resolved from
        // `waaseyaa/groups` (L2, downward from workflows' L3 — see
        // docs/specs/content-workflow.md "Layering").
        $this->singleton(GroupConstraintChecker::class, function (): GroupConstraintChecker {
            return new GroupConstraintChecker(
                membership: $this->resolve(GroupMembershipService::class),
            );
        });

        $this->singleton(TransitionService::class, function (): TransitionService {
            $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
            $auditWriter = $this->resolveOptional(AuditWriterInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);
            $groupConstraintChecker = $this->resolveOptional(GroupConstraintChecker::class);

            return new TransitionService(
                bindings: $this->resolve(WorkflowBindingResolver::class),
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
                dispatcher: $dispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface ? $dispatcher : null,
                auditWriter: $auditWriter instanceof AuditWriterInterface ? $auditWriter : null,
                logger: $logger instanceof LoggerInterface ? $logger : null,
                groupConstraintChecker: $groupConstraintChecker instanceof GroupConstraintChecker ? $groupConstraintChecker : null,
            );
        });

        // CW-v1 option-1 (#1920 PR-2, design §3.1): the arm-at-PRE_SAVE /
        // consume-at-POST_SAVE handoff between WorkflowStateGuard and
        // WorkflowRepublishListener below. A single shared instance —
        // singleton, not per-request-scoped beyond that — mirrors
        // WorkflowBindingResolver's own boot-stable-not-process-stable
        // convention (a new container/request constructs a fresh one).
        $this->singleton(RepublishMarker::class, static function (): RepublishMarker {
            return new RepublishMarker();
        });

        $this->singleton(WorkflowStateGuard::class, function (): WorkflowStateGuard {
            $accountContext = $this->resolveOptional(AccountContextInterface::class);
            $groupConstraintChecker = $this->resolveOptional(GroupConstraintChecker::class);
            $republishMarker = $this->resolveOptional(RepublishMarker::class);

            return new WorkflowStateGuard(
                bindings: $this->resolve(WorkflowBindingResolver::class),
                // CW-v1 WP-2 task 2.6 (#1920): needed to detect an existing
                // published pointer so a forward-draft save can leave
                // `status` alone instead of following the target state's
                // `published` flag directly (two-pointer status semantics).
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
                accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                // CW-v1 WP-3 (#1920): group-constraint parity on the
                // save-path guard — see TransitionService's own binding
                // above for the shared rationale.
                groupConstraintChecker: $groupConstraintChecker instanceof GroupConstraintChecker ? $groupConstraintChecker : null,
                republishMarker: $republishMarker instanceof RepublishMarker ? $republishMarker : null,
            );
        });

        // CW-v1 option-1 (#1920 PR-2, design §3.1): the POST_SAVE half of
        // the same-state republish two-step — wired onto the dispatcher in
        // boot() below, next to the other two guards.
        $this->singleton(WorkflowRepublishListener::class, function (): WorkflowRepublishListener {
            return new WorkflowRepublishListener(
                marker: $this->resolve(RepublishMarker::class),
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
            );
        });

        // CW-v1 WP-2 task 2.5 (#1920): closes the pointer-move bypass task
        // 2.4's BeforeRevisionPointerMoveEvent choke point exists for.
        $this->singleton(WorkflowPointerMoveGuard::class, function (): WorkflowPointerMoveGuard {
            $accountContext = $this->resolveOptional(AccountContextInterface::class);
            $groupConstraintChecker = $this->resolveOptional(GroupConstraintChecker::class);

            return new WorkflowPointerMoveGuard(
                bindings: $this->resolve(WorkflowBindingResolver::class),
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
                accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                // CW-v1 WP-3 (#1920): group-constraint parity on the
                // pointer-move guard — see TransitionService's own binding
                // above for the shared rationale.
                groupConstraintChecker: $groupConstraintChecker instanceof GroupConstraintChecker ? $groupConstraintChecker : null,
            );
        });
    }

    /**
     * Wires the PRE_SAVE save-path guard and seeds the default `editorial`
     * workflow (CW-v1 WP-1, docs/specs/content-workflow.md).
     *
     * Mirrors {@see \Waaseyaa\Relationship\RelationshipServiceProvider::boot()}:
     * the dispatcher MUST be resolved by the Symfony-contracts FQCN — the
     * foundation FQCN silently no-ops (the exact bug that left this
     * package's predecessor listener, `DomainValidationListener`, dead).
     *
     * Degraded mode — SAFE but LOUD (CLAUDE.md "Best-effort side effects"):
     * the engine services require `ConfigFactoryInterface`, which the kernel
     * container does not yet serve in a real production boot — tracked as
     * issue #1930 (the root fix: bind ConfigFactoryInterface in the kernel).
     * Until #1930 lands, `resolveOptional(WorkflowStateGuard::class)` below
     * catches the factory's RuntimeException and this method degrades: no
     * guard, no seed, no boot crash — and, critically, a WARNING is logged
     * naming #1930. Silent inertness is how the predecessor engine died;
     * the degradation is pinned by a dedicated test
     * (`GuardWiringTest::boot_degrades_safely_and_loudly_without_a_config_factory`)
     * so it stays intentional, visible behavior rather than an accident.
     */
    public function boot(): void
    {
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $guard = $this->resolveOptional(WorkflowStateGuard::class);
        if (!$guard instanceof WorkflowStateGuard) {
            $logger = $this->resolveOptional(LoggerInterface::class);
            ($logger instanceof LoggerInterface ? $logger : new NullLogger())->warning(
                'workflows.engine_not_wired',
                [
                    'reason' => 'WorkflowStateGuard unresolvable — ConfigFactoryInterface is not served by the kernel container',
                    'effect' => 'PRE_SAVE save-path guard NOT registered; default editorial workflow NOT seeded',
                    'tracking' => 'https://github.com/waaseyaa/framework/issues/1930 (bind ConfigFactoryInterface in the kernel)',
                ],
            );

            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            [$guard, 'onPreSave'],
        );

        // CW-v1 WP-2 task 2.5 (#1920): the pointer-move guard shares the same
        // degraded-mode gate as the save-path guard above (both need
        // WorkflowBindingResolver, which needs ConfigFactoryInterface — #1930).
        // Resolved separately (not from $guard) because it is a distinct
        // service with its own dependency (EntityTypeManagerInterface), not a
        // wrapper around WorkflowStateGuard.
        $pointerGuard = $this->resolveOptional(WorkflowPointerMoveGuard::class);
        if ($pointerGuard instanceof WorkflowPointerMoveGuard) {
            $dispatcher->addListener(
                BeforeRevisionPointerMoveEvent::class,
                [$pointerGuard, 'onBeforePointerMove'],
            );
        }

        // CW-v1 option-1 (#1920 PR-2, design §3.1): same degraded-mode gate
        // as the two guards above (WorkflowRepublishListener needs
        // EntityTypeManagerInterface, already guaranteed resolvable at this
        // point since $guard above resolved successfully — both share the
        // same dependency chain). Registered on POST_SAVE, the same event
        // {@see \Waaseyaa\Cache\Listener\EntityCacheSubscriber} and the
        // search/ai-vector indexers subscribe to.
        $republishListener = $this->resolveOptional(WorkflowRepublishListener::class);
        if ($republishListener instanceof WorkflowRepublishListener) {
            $dispatcher->addListener(
                EntityEvents::POST_SAVE->value,
                [$republishListener, 'onPostSave'],
            );
        }

        $this->seedDefaultEditorialWorkflow();
    }

    /**
     * Seeds the framework-default `editorial` workflow as config data (not
     * code — {@see DefaultWorkflows}), or additively tops it up if it already
     * exists. Log-and-skip on validation failure, never boot-crash (CLAUDE.md
     * "seeding is log-and-skip on invalid, never boot-crash" — a known
     * judgment call the plan pins).
     *
     * Uses `getRepository('workflow')`, not `getStorage()`: production kernel
     * wiring passes `storageFactory: null` to `EntityTypeManager`
     * (`EntityTypeManagerFactory::build()`, C-22 WP4), so `getStorage()`
     * throws for the `workflow` entity type (no `storageClass` declared).
     * `getRepository()` is the live pipeline for every entity type.
     *
     * Upgrade contract (WP-2 rework Task 2, #1920, final-review finding #7):
     * the boot seed guarantees the shipped `DefaultWorkflows::EDITORIAL`
     * state/transition SET exists on the persisted `editorial` entity,
     * version-independently of when that entity was first created. An
     * alpha.256-era install persisted `editorial` before
     * `restore_to_published` existed; returning early here (the old
     * behaviour) would keep that install's archived content unrecoverable
     * forever. Operators customize the shipped workflow by editing existing
     * entries (preserved verbatim by the top-up) or by binding their own
     * workflow id via `workflows.assignments` — DELETING a shipped
     * state/transition is re-added at the next boot.
     */
    private function seedDefaultEditorialWorkflow(): void
    {
        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $logger = $this->resolveOptional(LoggerInterface::class);
        $resolvedLogger = $logger instanceof LoggerInterface ? $logger : new NullLogger();

        $repository = $entityTypeManager->getRepository('workflow');
        $existing = $repository->find('editorial');

        if ($existing instanceof Workflow) {
            $this->topUpDefaultEditorialWorkflow($existing, $repository, $resolvedLogger);

            return;
        }

        $workflow = new Workflow(DefaultWorkflows::EDITORIAL);
        $violations = new WorkflowValidator()->validate($workflow);
        if ($violations !== []) {
            $resolvedLogger->warning('workflows.default_seed_invalid', ['violations' => $violations]);

            return;
        }

        $workflow->enforceIsNew();

        try {
            $repository->save($workflow);
        } catch (\Throwable $e) {
            $resolvedLogger->warning('workflows.default_seed_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Version-independent additive top-up (#7): adds any state or transition
     * present in {@see DefaultWorkflows::EDITORIAL} but absent BY MACHINE
     * NAME from the persisted `$existing` entity. Never modifies or removes
     * an entry that already exists — an operator-customized `permission`
     * string (or any other field) on a pre-existing transition survives
     * untouched. If nothing is missing, this performs no save at all (steady
     * state is read + compare only).
     *
     * `$existing` was loaded via `EntityRepositoryInterface::find()`, so its
     * `isNew()` is already false — the merge is saved as an UPDATE of the
     * same `editorial` id, never a re-create.
     */
    private function topUpDefaultEditorialWorkflow(
        Workflow $existing,
        EntityRepositoryInterface $repository,
        LoggerInterface $logger,
    ): void {
        $addedStates = $this->addMissingStates($existing, DefaultWorkflows::EDITORIAL['states']);
        $addedTransitions = $this->addMissingTransitions($existing, DefaultWorkflows::EDITORIAL['transitions']);

        if ($addedStates === [] && $addedTransitions === []) {
            // Steady state: the persisted entity already carries the full
            // shipped set. Read + compare only — write nothing.
            return;
        }

        $violations = new WorkflowValidator()->validate($existing);
        if ($violations !== []) {
            $logger->warning('workflows.default_seed_topup_invalid', ['violations' => $violations]);

            return;
        }

        try {
            $repository->save($existing);
        } catch (\Throwable $e) {
            $logger->warning('workflows.default_seed_topup_failed', ['error' => $e->getMessage()]);

            return;
        }

        $logger->info('workflows.default_seed_topup', [
            'added_transitions' => $addedTransitions,
            'added_states' => $addedStates,
        ]);
    }

    /**
     * Adds every shipped state absent (by machine name) from `$existing`,
     * mutating it in place. Never touches a state that already exists.
     *
     * @param array<string, mixed> $shippedStates {@see DefaultWorkflows::EDITORIAL}'s 'states' entry.
     * @return list<string> Machine names of the states that were added.
     */
    private function addMissingStates(Workflow $existing, array $shippedStates): array
    {
        $added = [];

        foreach ($shippedStates as $stateId => $stateData) {
            if ($existing->hasState($stateId) || !\is_array($stateData)) {
                continue;
            }

            $existing->addState(new WorkflowState(
                id: $stateId,
                label: (string) ($stateData['label'] ?? $stateId),
                weight: (int) ($stateData['weight'] ?? 0),
                metadata: (array) ($stateData['metadata'] ?? []),
                published: (bool) ($stateData['published'] ?? false),
                defaultRevision: (bool) ($stateData['default_revision'] ?? false),
            ));
            $added[] = $stateId;
        }

        return $added;
    }

    /**
     * Adds every shipped transition absent (by machine name) from
     * `$existing`, mutating it in place. Never touches a transition that
     * already exists — an operator-customized `permission` (or any other
     * field) on a pre-existing transition survives untouched.
     *
     * @param array<string, mixed> $shippedTransitions {@see DefaultWorkflows::EDITORIAL}'s 'transitions' entry.
     * @return list<string> Machine names of the transitions that were added.
     */
    private function addMissingTransitions(Workflow $existing, array $shippedTransitions): array
    {
        $added = [];

        foreach ($shippedTransitions as $transitionId => $transitionData) {
            if ($existing->hasTransition($transitionId) || !\is_array($transitionData)) {
                continue;
            }

            $existing->addTransition(new WorkflowTransition(
                id: $transitionId,
                label: (string) ($transitionData['label'] ?? $transitionId),
                from: (array) ($transitionData['from'] ?? []),
                to: (string) ($transitionData['to'] ?? ''),
                weight: (int) ($transitionData['weight'] ?? 0),
                permission: (string) ($transitionData['permission'] ?? ''),
                groupConstraint: isset($transitionData['group_constraint'])
                    ? (string) $transitionData['group_constraint']
                    : null,
            ));
            $added[] = $transitionId;
        }

        return $added;
    }
}
