<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Workflows\AuthoringRoleMatrix;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;

/**
 * Read-only HTTP controller for the workflow-guards matrix surface (M4A-5
 * Phase 1, mission `workflow-guards-readonly-01KSDS5W`, parent #1470).
 *
 * Exposes the `(workflow_id, bundle, transition) -> required_roles` rules
 * encoded by {@see AuthoringRoleMatrix} so operators can see *why* a
 * dry-run verdict (M4A-4) was reached without dumping framework source.
 *
 * Layout mirrors {@see \Waaseyaa\Api\Workflow\WorkflowDefinitionsController}
 * (M4A-1) — a closure named `$workflowsProvider` plays the role of the
 * "workflow registry service" (FR-002, FR-003). The same closure is used
 * by {@see \Waaseyaa\Api\Workflow\WorkflowDryRunController} for symmetry,
 * so swapping registries in one place automatically covers the guards
 * endpoint too.
 *
 * Access control: enforced by the route option `_role: admin` (see
 * `BuiltinRouteRegistrar`). NFR pattern — do NOT re-check role here.
 *
 * Phase 2 (mutation) is deferred: see follow-up issue M4A-5b.
 *
 * @api
 */
final class WorkflowGuardsController
{
    /**
     * @var \Closure(): list<Workflow>
     */
    private \Closure $workflowsProvider;

    /**
     * @param (\Closure(): list<Workflow>)|null $workflowsProvider Defaults to the
     *   editorial preset factory; when multiple workflows become pluggable,
     *   the API service provider supplies a registry-backed iterator.
     */
    public function __construct(
        private readonly AuthoringRoleMatrix $matrix,
        ?\Closure $workflowsProvider = null,
    ) {
        $this->workflowsProvider = $workflowsProvider
            ?? static fn(): array => [EditorialWorkflowPreset::create()];
    }

    /**
     * `GET /api/workflow-definitions/{workflow_id}/guards`
     *
     * Returns the bundle × transition × required-roles rows for the named
     * workflow. Returns a 404-shaped error envelope when the workflow is
     * not registered (FR-003).
     *
     * @return array{data: list<array{bundle: string, transition: string, required_roles: list<string>}>}
     *   |array{errors: list<array{status: string, title: string, detail: string}>, status: int}
     */
    public function index(string $workflow_id): array
    {
        $workflow = array_find(
            ($this->workflowsProvider)(),
            static fn(Workflow $w): bool => $w->id() === $workflow_id,
        );

        if ($workflow === null) {
            return [
                'status' => 404,
                'errors' => [[
                    'status' => '404',
                    'title' => 'Not Found',
                    'detail' => sprintf('Workflow "%s" not found.', $workflow_id),
                ]],
            ];
        }

        return [
            'data' => $this->matrix->forWorkflow($workflow_id),
        ];
    }
}
