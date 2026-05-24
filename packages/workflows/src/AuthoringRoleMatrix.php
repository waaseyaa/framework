<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Configurable authoring role matrix.
 *
 * Generates bundle-scoped permission sets from role definitions.
 * Applications define their own roles and permission templates;
 * the framework handles bundle expansion.
 *
 * Permission templates use `{bundle}` as a placeholder that gets
 * expanded for each bundle in the matrix.
 *
 * Example role definition:
 *   [
 *       'label' => 'Contributor',
 *       'permissions' => ['access content'],
 *       'bundle_permissions' => [
 *           'create {bundle} content',
 *           'edit own {bundle} content',
 *       ],
 *   ]
 *
 * ## Workflow guard surface (M4A-5 Phase 1)
 *
 * The matrix can also carry a workflow-guard mapping that records, per
 * workflow, the roles allowed to perform each named transition. The data
 * is optional: callers that only need permission-expansion can omit it.
 * When present, {@see snapshot()} flattens the mapping cross-producted
 * with the configured bundles into a stable read-only row set keyed by
 * `(workflow_id, bundle, transition)`. The guard surface is read-only;
 * editing it is deferred to M4A-5b.
 *
 * @api
 */
final class AuthoringRoleMatrix
{
    /**
     * @param list<string> $bundles Content bundles to expand permissions for
     * @param array<string, array{label: string, permissions?: list<string>, bundle_permissions?: list<string>}> $roles
     *   Role definitions keyed by role ID. Each role has:
     *   - label: Human-readable name
     *   - permissions: (optional) Static permissions not scoped to bundles
     *   - bundle_permissions: (optional) Templates with {bundle} placeholder
     * @param array<string, array<string, list<string>>> $workflowGuards
     *   Optional workflow-guard mapping keyed by `workflow_id => [transition_id => list<role>]`.
     *   When empty (default), {@see snapshot()} and {@see forWorkflow()} return empty arrays.
     *   The framework's editorial workflow surfaces this via
     *   {@see EditorialTransitionAccessResolver::TRANSITION_ROLE_MATRIX}.
     */
    public function __construct(
        private readonly array $bundles,
        private readonly array $roles,
        private readonly array $workflowGuards = [],
    ) {}

    /**
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach ($this->roles as $roleId => $definition) {
            $permissions = $definition['permissions'] ?? [];
            $bundleTemplates = $definition['bundle_permissions'] ?? [];

            foreach ($this->bundles as $bundle) {
                foreach ($bundleTemplates as $template) {
                    $permissions[] = str_replace('{bundle}', $bundle, $template);
                }
            }

            $matrix[$roleId] = [
                'label' => $definition['label'],
                'permissions' => array_values(array_unique($permissions)),
            ];
        }

        return $matrix;
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function permissionsForRoles(array $roles): array
    {
        $matrix = $this->matrix();
        $permissions = [];

        foreach ($roles as $role) {
            if (!isset($matrix[$role]) || !is_array($matrix[$role]['permissions'] ?? null)) {
                continue;
            }
            array_push($permissions, ...$matrix[$role]['permissions']);
        }

        $permissions = array_values(array_unique(array_map(
            static fn(string $permission): string => trim($permission),
            array_filter($permissions, static fn(mixed $permission): bool => is_string($permission) && trim($permission) !== ''),
        )));
        sort($permissions);

        return $permissions;
    }

    /**
     * Flatten the workflow-guard mapping into ordered rows.
     *
     * Each row carries `(workflow_id, bundle, transition, required_roles)`.
     * Rows are deterministically ordered by workflow_id, bundle, transition.
     * When no bundles are configured, no rows are emitted (a guard without a
     * bundle context cannot be applied).
     *
     * @return list<array{workflow_id: string, bundle: string, transition: string, required_roles: list<string>}>
     */
    public function snapshot(): array
    {
        $rows = [];

        $workflowIds = array_keys($this->workflowGuards);
        sort($workflowIds);

        foreach ($workflowIds as $workflowId) {
            foreach ($this->forWorkflow($workflowId) as $row) {
                $rows[] = [
                    'workflow_id' => $workflowId,
                    'bundle' => $row['bundle'],
                    'transition' => $row['transition'],
                    'required_roles' => $row['required_roles'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Return the guard rows for a single workflow id.
     *
     * Returns an empty list when the workflow is unknown to the matrix.
     * Rows are ordered by bundle, then transition.
     *
     * @return list<array{bundle: string, transition: string, required_roles: list<string>}>
     */
    public function forWorkflow(string $workflowId): array
    {
        $transitions = $this->workflowGuards[$workflowId] ?? [];
        if ($transitions === []) {
            return [];
        }

        $transitionIds = array_keys($transitions);
        sort($transitionIds);

        $bundles = $this->bundles;
        sort($bundles);

        $rows = [];
        foreach ($bundles as $bundle) {
            foreach ($transitionIds as $transitionId) {
                $rows[] = [
                    'bundle' => $bundle,
                    'transition' => $transitionId,
                    'required_roles' => $transitions[$transitionId] ?? [],
                ];
            }
        }

        return $rows;
    }

    /**
     * Returns the workflow ids that carry guard data in this matrix.
     *
     * Used by the API service provider to verify that a requested
     * workflow id is known before delegating to {@see forWorkflow()}.
     *
     * @return list<string>
     */
    public function knownWorkflowIds(): array
    {
        $ids = array_keys($this->workflowGuards);
        sort($ids);

        return $ids;
    }
}
