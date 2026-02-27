<?php

declare(strict_types=1);

namespace Aurora\Workflows;

/**
 * Service for managing content moderation state transitions.
 *
 * The ContentModerator holds a registry of workflows and provides
 * methods to transition content between states within those workflows,
 * enforcing the allowed transitions.
 */
final class ContentModerator
{
    /**
     * @param array<string, Workflow> $workflows Keyed by workflow ID.
     */
    public function __construct(
        private array $workflows = [],
    ) {}

    /**
     * Register a workflow with the moderator.
     */
    public function addWorkflow(Workflow $workflow): void
    {
        $id = $workflow->id();
        if ($id === null) {
            throw new \InvalidArgumentException('Cannot add a workflow without an ID.');
        }

        $this->workflows[(string) $id] = $workflow;
    }

    /**
     * Get a workflow by its ID.
     */
    public function getWorkflow(string $workflowId): ?Workflow
    {
        return $this->workflows[$workflowId] ?? null;
    }

    /**
     * Attempt to transition an entity's moderation state.
     *
     * Looks up the workflow, verifies the transition is allowed, and
     * returns a new ContentModerationState with the target state.
     *
     * @throws \InvalidArgumentException If the workflow is not found or the
     *   transition is not allowed.
     */
    public function transition(
        ContentModerationState $currentState,
        string $toStateId,
    ): ContentModerationState {
        $workflow = $this->workflows[$currentState->workflowId] ?? null;

        if ($workflow === null) {
            throw new \InvalidArgumentException(\sprintf(
                'Workflow "%s" not found.',
                $currentState->workflowId,
            ));
        }

        if (!$workflow->isTransitionAllowed($currentState->stateId, $toStateId)) {
            throw new \InvalidArgumentException(\sprintf(
                'Transition from "%s" to "%s" is not allowed in workflow "%s".',
                $currentState->stateId,
                $toStateId,
                $currentState->workflowId,
            ));
        }

        return new ContentModerationState(
            entityTypeId: $currentState->entityTypeId,
            entityId: $currentState->entityId,
            workflowId: $currentState->workflowId,
            stateId: $toStateId,
        );
    }

    /**
     * Get available transitions from the current moderation state.
     *
     * @return WorkflowTransition[]
     *
     * @throws \InvalidArgumentException If the workflow is not found.
     */
    public function getAvailableTransitions(
        ContentModerationState $currentState,
    ): array {
        $workflow = $this->workflows[$currentState->workflowId] ?? null;

        if ($workflow === null) {
            throw new \InvalidArgumentException(\sprintf(
                'Workflow "%s" not found.',
                $currentState->workflowId,
            ));
        }

        return $workflow->getValidTransitions($currentState->stateId);
    }
}
