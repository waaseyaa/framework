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
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
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

        $this->singleton(TransitionService::class, function (): TransitionService {
            $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
            $auditWriter = $this->resolveOptional(AuditWriterInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);

            return new TransitionService(
                bindings: $this->resolve(WorkflowBindingResolver::class),
                entityTypeManager: $this->resolve(EntityTypeManagerInterface::class),
                dispatcher: $dispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface ? $dispatcher : null,
                auditWriter: $auditWriter instanceof AuditWriterInterface ? $auditWriter : null,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            );
        });

        $this->singleton(WorkflowStateGuard::class, function (): WorkflowStateGuard {
            $accountContext = $this->resolveOptional(AccountContextInterface::class);

            return new WorkflowStateGuard(
                bindings: $this->resolve(WorkflowBindingResolver::class),
                accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
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

        $this->seedDefaultEditorialWorkflow();
    }

    /**
     * Seeds the framework-default `editorial` workflow as config data (not
     * code — {@see DefaultWorkflows}), unless it already exists. Log-and-skip
     * on validation failure, never boot-crash (CLAUDE.md "seeding is
     * log-and-skip on invalid, never boot-crash" — a known judgment call the
     * plan pins).
     *
     * Uses `getRepository('workflow')`, not `getStorage()`: production kernel
     * wiring passes `storageFactory: null` to `EntityTypeManager`
     * (`EntityTypeManagerFactory::build()`, C-22 WP4), so `getStorage()`
     * throws for the `workflow` entity type (no `storageClass` declared).
     * `getRepository()` is the live pipeline for every entity type.
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
        if ($repository->find('editorial') !== null) {
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
}
