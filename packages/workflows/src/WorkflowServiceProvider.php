<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

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
    }
}
