<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Workflow;

use Waaseyaa\Workflows\Workflow;

/**
 * API controller for workflow definitions (read-only).
 *
 * JSON payloads use camelCase keys aligned with the admin SPA TypeScript
 * types ({@see useWorkflowDefinitions}). M4A-1 covers the list endpoint only;
 * detail page, transition history, dry-run, and guard editing land in
 * follow-up sub-missions (M4A-2..M4A-5).
 *
 * @api
 */
final class WorkflowDefinitionsController
{
    /**
     * Optional factory override. Production wiring
     * ({@see \Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter})
     * supplies a provider backed by {@see \Waaseyaa\Workflows\Read\ActiveWorkflows}
     * — the active, verified `workflows.assignments` configuration. With no
     * provider (an unwired install, or a `core`-only install with no
     * `waaseyaa/workflows` package at all) this defaults to a well-formed
     * empty result.
     *
     * #2835: this default used to be the retired `EditorialWorkflowPreset`,
     * which had already drifted from the live editorial transition set —
     * a plausible-looking but fictional workflow is worse than an honest
     * empty list.
     *
     * @var \Closure(): list<Workflow>
     */
    private \Closure $workflowsProvider;

    /**
     * @param (\Closure(): list<Workflow>)|null $workflowsProvider
     */
    public function __construct(?\Closure $workflowsProvider = null)
    {
        $this->workflowsProvider = $workflowsProvider
            ?? static fn(): array => [];
    }

    /**
     * GET /api/workflow-definitions
     *
     * @return array{data: list<array{
     *   id: string,
     *   label: string,
     *   states: list<array{id: string, label: string, weight: int, metadata: array<string, mixed>}>,
     *   transitions: list<array{id: string, label: string, from: list<string>, to: string, weight: int}>
     * }>}
     */
    public function list(): array
    {
        $workflows = ($this->workflowsProvider)();

        return [
            'data' => array_map(self::serializeWorkflow(...), $workflows),
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   label: string,
     *   states: list<array{id: string, label: string, weight: int, metadata: array<string, mixed>}>,
     *   transitions: list<array{id: string, label: string, from: list<string>, to: string, weight: int}>
     * }
     */
    private static function serializeWorkflow(Workflow $workflow): array
    {
        $states = [];
        foreach ($workflow->getStates() as $state) {
            $states[] = [
                'id' => $state->id,
                'label' => $state->label,
                'weight' => $state->weight,
                'metadata' => $state->metadata,
            ];
        }

        $transitions = [];
        foreach ($workflow->getTransitions() as $transition) {
            $transitions[] = [
                'id' => $transition->id,
                'label' => $transition->label,
                'from' => array_values($transition->from),
                'to' => $transition->to,
                'weight' => $transition->weight,
            ];
        }

        return [
            'id' => $workflow->id(),
            'label' => $workflow->label(),
            'states' => $states,
            'transitions' => $transitions,
        ];
    }
}
